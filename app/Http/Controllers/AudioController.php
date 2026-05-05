<?php

namespace App\Http\Controllers;

use App\Models\SoundFolder;
use App\Models\SoundTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AudioController extends Controller
{
    private const STREAM_CHUNK_BYTES = 2 * 1024 * 1024; // 2 MB per open-ended range

    /**
     * Pick a random track from the given folder slug. Returns track metadata
     * (id, stream URL, duration, default mode). Frontend then plays via Howler.
     */
    public function pick(string $slug): JsonResponse
    {
        $aliases = $this->worldSlugAliases($slug);
        $folders = SoundFolder::whereIn('slug', $aliases)->get()->keyBy('slug');

        if ($folders->isEmpty()) {
            return response()->json([
                'error' => 'Folder not found',
                'folderSlug' => $slug,
            ], 404);
        }

        $orderedSlugs = array_values(array_unique([$slug, ...$aliases]));
        $folder = null;
        $track = null;

        foreach ($orderedSlugs as $candidateSlug) {
            $candidateFolder = $folders->get($candidateSlug);
            if (! $candidateFolder) {
                continue;
            }

            $candidateTrack = SoundTrack::where('sound_folder_id', $candidateFolder->id)
                ->inRandomOrder()
                ->first();

            if ($candidateTrack) {
                $folder = $candidateFolder;
                $track = $candidateTrack;
                break;
            }
        }

        if (! $track) {
            $fallbackFolder = $folders->get($slug) ?? $folders->first();
            return response()->json([
                'error' => 'No tracks indexed in folder',
                'folderSlug' => $fallbackFolder?->slug ?? $slug,
            ], 404);
        }


        return response()->json([
            'trackId' => $track->id,
            'streamUrl' => route('audio.stream', ['track' => $track->id]),
            'durationSec' => $track->duration_seconds,
            'mode' => $folder->mode,
            'folderSlug' => $folder->slug,
            'folderName' => $folder->name,
            // The stream URL has no file extension (it's a numeric track id), so
            // browsers/Howler cannot sniff the codec. Send the original extension
            // so the client can pass `format: [ext]` to <audio>.
            'format' => strtolower(pathinfo($track->file_path, PATHINFO_EXTENSION)),
        ]);
    }

    /**
     * Stream a stored audio file. Auth-protected.
     *
     * Uses StreamedResponse with manual Range support because:
     *   - response()->file() (Symfony BinaryFileResponse) does NOT honour the
     *     Range header on its own under php artisan serve; it always returns
     *     the whole file with 200 OK, which can confuse <audio> elements.
     *   - Howler with html5: true relies on the browser audio element, which
     *     sends Range: bytes=0- on every load and expects 206 Partial Content.
     */
    public function stream(SoundTrack $track, Request $request)
    {
        // Release session file lock before long-running stream response.
        // Without this, same-user page navigations can block until the stream
        // request ends (especially visible on php artisan serve / file sessions).
        if ($request->hasSession()) {
            $request->session()->save();
        }
        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        $disk = Storage::disk('local');
        $relative = $this->resolveTrackStoragePath($disk, $track->file_path);

        // During large audio sync/replace operations a picked row may briefly
        // point to a file being swapped. Fallback to another existing track from
        // same folder so playback doesn't abruptly stop with 404.
        if (! $relative) {
            $fallback = SoundTrack::query()
                ->where('sound_folder_id', $track->sound_folder_id)
                ->whereKeyNot($track->id)
                ->inRandomOrder()
                ->limit(40)
                ->get(['file_path'])
                ->first(fn (SoundTrack $candidate) => (bool) $this->resolveTrackStoragePath($disk, $candidate->file_path));

            if (! $fallback) {
                return response('Audio file missing', 404);
            }

            $relative = $this->resolveTrackStoragePath($disk, $fallback->file_path);
            if (! $relative) {
                return response('Audio file missing', 404);
            }
        }

        if ($this->shouldUseNginxAccel()) {
            return response('', 200, [
                'Content-Type' => $this->mimeFor($relative),
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, max-age=31536000',
                'X-Accel-Redirect' => $this->buildAccelInternalUri($relative),
            ]);
        }

        $path = $disk->path($relative);
        $size = filesize($path);
        $mime = $this->mimeFor($path);

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=31536000',
            'Content-Length' => $size,
        ];

        if ($range = $request->header('Range')) {
            if (preg_match('/bytes=(\d+)-(\d*)/', $range, $m)) {
                $start = (int) $m[1];
                // Open-ended Range (bytes=N-) can be huge and tie up local
                // single-worker servers for seconds. Cap each response chunk so
                // browser continues with follow-up ranges and UI stays responsive.
                $end = $m[2] !== ''
                    ? (int) $m[2]
                    : min($start + self::STREAM_CHUNK_BYTES - 1, $size - 1);
                $end = min($end, $size - 1);
                $status = 206;
                $headers['Content-Length'] = $end - $start + 1;
                $headers['Content-Range'] = "bytes $start-$end/$size";
            }
        }

        return response()->stream(function () use ($path, $start, $end) {
            $fh = fopen($path, 'rb');
            fseek($fh, $start);
            $remaining = $end - $start + 1;
            $chunk = 8192;
            while ($remaining > 0 && ! feof($fh)) {
                $read = min($chunk, $remaining);
                echo fread($fh, $read);
                flush();
                $remaining -= $read;
            }
            fclose($fh);
        }, $status, $headers);
    }

    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'm4a', 'aac' => 'audio/mp4',
            'ogg', 'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            default => 'application/octet-stream',
        };
    }

    /** @return string[] */
    private function worldSlugAliases(string $slug): array
    {
        $aliases = [$slug];

        if (str_contains($slug, '/other-world/')) {
            $aliases[] = str_replace('/other-world/', '/outer-world/', $slug);
        }
        if (str_contains($slug, '/outer-world/')) {
            $aliases[] = str_replace('/outer-world/', '/other-world/', $slug);
        }

        return array_values(array_unique($aliases));
    }

    private function alternateWorldPath(string $path): ?string
    {
        if (str_contains($path, '/other-world/')) {
            return str_replace('/other-world/', '/outer-world/', $path);
        }
        if (str_contains($path, '/outer-world/')) {
            return str_replace('/outer-world/', '/other-world/', $path);
        }
        return null;
    }

    private function shouldUseNginxAccel(): bool
    {
        return (bool) config('eh.audio_accel_enabled', false);
    }

    private function buildAccelInternalUri(string $relativePath): string
    {
        $prefix = '/'.trim((string) config('eh.audio_accel_internal_prefix', '/_protected-audio/'), '/').'/';
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');

        // Guard against header path traversal if DB data is tampered.
        if (
            $normalized === ''
            || str_starts_with($normalized, '..')
            || str_contains($normalized, '/../')
        ) {
            abort(404);
        }

        return $prefix.$normalized;
    }

    private function resolveTrackStoragePath($disk, string $trackFilePath): ?string
    {
        $root = config('eh.audio_root', 'audio');
        $primary = $root.'/'.$trackFilePath;
        $resolved = $this->resolveCaseAndUnicodePath($disk, $primary);
        if ($resolved) {
            return $resolved;
        }

        $alternate = $this->alternateWorldPath($trackFilePath);
        if (! $alternate) {
            return null;
        }

        return $this->resolveCaseAndUnicodePath($disk, $root.'/'.$alternate);
    }

    private function resolveCaseAndUnicodePath($disk, string $relative): ?string
    {
        if ($disk->exists($relative)) {
            return $relative;
        }

        $normalized = ltrim(str_replace('\\', '/', $relative), '/');
        $dir = trim((string) pathinfo($normalized, PATHINFO_DIRNAME), '/');
        $file = (string) pathinfo($normalized, PATHINFO_BASENAME);
        if ($file === '') {
            return null;
        }

        $targetKey = $this->pathLookupKey($file);
        foreach ($disk->files($dir === '.' ? '' : $dir) as $candidate) {
            $candidateFile = (string) pathinfo($candidate, PATHINFO_BASENAME);
            if ($this->pathLookupKey($candidateFile) === $targetKey) {
                return $candidate;
            }
        }

        return null;
    }

    private function pathLookupKey(string $value): string
    {
        $key = mb_strtolower($value, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($key, \Normalizer::FORM_KD);
            if ($normalized !== false && $normalized !== null) {
                $key = $normalized;
            }
            $withoutMarks = preg_replace('/\p{Mn}+/u', '', $key);
            if (is_string($withoutMarks)) {
                $key = $withoutMarks;
            }
        }

        return $key;
    }
}
