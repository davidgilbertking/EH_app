<?php

namespace App\Http\Controllers;

use App\Models\SoundFolder;
use App\Models\SoundTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class AudioController extends Controller
{
    /**
     * Pick a random track from the given folder slug. Returns track metadata
     * (id, stream URL, duration, default mode). Frontend then plays via Howler.
     */
    public function pick(string $slug): JsonResponse
    {
        $folder = SoundFolder::where('slug', $slug)->firstOrFail();

        $track = SoundTrack::where('sound_folder_id', $folder->id)
            ->inRandomOrder()
            ->first();

        if (! $track) {
            return response()->json([
                'error' => 'No tracks indexed in folder',
                'folderSlug' => $folder->slug,
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
    public function stream(SoundTrack $track, \Illuminate\Http\Request $request)
    {
        $disk = Storage::disk('local');
        $relative = config('eh.audio_root', 'audio').'/'.$track->file_path;

        if (! $disk->exists($relative)) {
            return response('Audio file missing', 404);
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
                $end = $m[2] !== '' ? (int) $m[2] : $end;
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
}
