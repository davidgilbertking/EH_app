<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use App\Models\SoundFolder;
use App\Models\SoundTrack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

#[Signature('audio:scan
    {--folder= : Limit to a specific folder slug}
    {--skip-loudness : Skip LUFS/peak analysis and only refresh file index}
    {--recompute-loudness : Recalculate loudness even when already stored}
    {--loudness-timeout-sec= : ffmpeg timeout in seconds for loudness analysis}
')]
#[Description('Walk storage/app/private/audio/<slug>/ for each sound_folder and refresh sound_tracks rows.')]
class AudioScanCommand extends Command
{
    private const LOUDNORM_FILTER = 'loudnorm=I=-16:TP=-1.0:LRA=11:print_format=json';
    private const DEFAULT_LOUDNESS_ANALYSIS_TIMEOUT_SEC = 180;

    public function handle(): int
    {
        $audioRoot = config('eh.audio_root', 'audio');
        $disk = Storage::disk('local');
        $only = $this->option('folder');
        $skipLoudness = (bool) $this->option('skip-loudness');
        $recomputeLoudness = (bool) $this->option('recompute-loudness');
        $analyzeLoudness = ! $skipLoudness;
        $loudnessTimeoutSec = $this->resolveLoudnessTimeoutSec();

        if ($analyzeLoudness && ! $this->ffmpegAvailable()) {
            $this->warn('ffmpeg is not available in PATH. Loudness analysis skipped.');
            $analyzeLoudness = false;
        }

        $folders = SoundFolder::query()
            ->when($only, fn ($q) => $q->where('slug', $only))
            ->orderBy('slug')
            ->get();

        if ($folders->isEmpty()) {
            $this->error('No matching sound_folders. Run db:seed first.');
            return self::FAILURE;
        }

        $getID3 = class_exists(\getID3::class) ? new \getID3() : null;

        $totalFound = 0;
        $totalIndexed = 0;
        $totalLoudnessAnalyzed = 0;
        $totalLoudnessFailed = 0;
        $totalLoudnessSkipped = 0;
        $missingFolders = [];

        foreach ($folders as $folder) {
            $rel = $audioRoot.'/'.$folder->slug;
            $abs = $disk->path($rel);

            if (! is_dir($abs)) {
                $missingFolders[] = $folder->slug;
                continue;
            }

            $files = collect($disk->files($rel))
                ->filter(fn ($p) => preg_match('/\.(mp3|m4a|aac|ogg|oga|wav|flac)$/i', $p))
                ->map(fn ($p) => ltrim(substr($p, strlen($rel) + 1), '/'))
                ->values();

            $totalFound += $files->count();

            // Reconcile DB rows: insert new, drop missing.
            $existingTracks = SoundTrack::where('sound_folder_id', $folder->id)
                ->get()
                ->keyBy('file_path');
            $existing = $existingTracks->keys()->all();
            $relativePaths = $files->map(fn ($p) => $folder->slug.'/'.$p)->all();

            $stale = array_diff($existing, $relativePaths);
            if ($stale) {
                SoundTrack::where('sound_folder_id', $folder->id)
                    ->whereIn('file_path', $stale)
                    ->delete();
            }

            foreach ($files as $relName) {
                $absFile = $disk->path($audioRoot.'/'.$folder->slug.'/'.$relName);
                $dur = null;
                if ($getID3) {
                    $info = @$getID3->analyze($absFile);
                    $dur = $info['playtime_seconds'] ?? null;
                }
                $dbPath = $folder->slug.'/'.$relName;
                $track = SoundTrack::updateOrCreate(
                    ['sound_folder_id' => $folder->id, 'file_path' => $dbPath],
                    ['duration_seconds' => $dur ? round((float) $dur, 3) : null],
                );

                if ($analyzeLoudness) {
                    $existingTrack = $existingTracks->get($dbPath);
                    $alreadyAnalyzed = $existingTrack && $this->hasLoudnessAnalysis($existingTrack);
                    if (! $recomputeLoudness && $alreadyAnalyzed) {
                        $totalLoudnessSkipped++;
                    } else {
                        $analysis = $this->analyzeTrackLoudness($absFile, $loudnessTimeoutSec);
                        if ($analysis) {
                            $track->fill($analysis)->save();
                            $totalLoudnessAnalyzed++;
                        } else {
                            $totalLoudnessFailed++;
                        }
                    }
                }

                $totalIndexed++;
            }
        }

        $this->info(sprintf(
            'Scanned %d folders. Found %d audio files; indexed %d tracks.',
            $folders->count(),
            $totalFound,
            $totalIndexed,
        ));
        if ($analyzeLoudness) {
            $this->line(sprintf(
                'Loudness analyzed: %d, skipped(existing): %d, failed: %d.',
                $totalLoudnessAnalyzed,
                $totalLoudnessSkipped,
                $totalLoudnessFailed,
            ));
        }

        if ($missingFolders) {
            $this->warn(count($missingFolders).' folders missing on disk:');
            foreach (array_slice($missingFolders, 0, 20) as $slug) {
                $this->line("  - $slug");
            }
            if (count($missingFolders) > 20) {
                $this->line('  …'.(count($missingFolders) - 20).' more');
            }
        }

        return self::SUCCESS;
    }

    private function ffmpegAvailable(): bool
    {
        try {
            $probe = new Process(['ffmpeg', '-version']);
            $probe->setTimeout(8);
            $probe->run();
            return $probe->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasLoudnessAnalysis(SoundTrack $track): bool
    {
        return $track->integrated_lufs !== null
            && $track->normalization_gain_db !== null;
    }

    /** @return array<string, mixed>|null */
    private function analyzeTrackLoudness(string $absoluteFilePath, int $timeoutSec): ?array
    {
        try {
            $process = new Process([
                'ffmpeg',
                '-hide_banner',
                '-nostats',
                '-i',
                $absoluteFilePath,
                '-map',
                '0:a:0',
                '-af',
                self::LOUDNORM_FILTER,
                '-f',
                'null',
                '-',
            ]);
            $process->setTimeout($timeoutSec);
            $process->run();
            $output = $process->getErrorOutput()."\n".$process->getOutput();
        } catch (\Throwable) {
            return null;
        }

        $payload = $this->extractLoudnormPayload($output);
        if (! $payload) {
            return null;
        }

        $integratedLufs = $this->toFiniteFloat($payload['input_i'] ?? null);
        if ($integratedLufs === null) {
            return null;
        }
        $truePeakDbtp = $this->toFiniteFloat($payload['input_tp'] ?? null);
        $normalizationGainDb = $this->calculateNormalizationGainDb($integratedLufs, $truePeakDbtp);

        return [
            'integrated_lufs' => round($integratedLufs, 3),
            'true_peak_dbtp' => $truePeakDbtp !== null ? round($truePeakDbtp, 3) : null,
            'normalization_gain_db' => $normalizationGainDb,
            'loudness_analyzed_at' => now(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function extractLoudnormPayload(string $output): ?array
    {
        $start = strrpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($output, $start, $end - $start + 1);
        if (! is_string($json) || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function toFiniteFloat(mixed $value): ?float
    {
        if ($value === null) return null;
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || strcasecmp($trimmed, 'inf') === 0 || strcasecmp($trimmed, '-inf') === 0) {
                return null;
            }
            if (! is_numeric($trimmed)) {
                return null;
            }
            $n = (float) $trimmed;
            return is_finite($n) ? $n : null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        return is_finite($n) ? $n : null;
    }

    private function calculateNormalizationGainDb(float $integratedLufs, ?float $truePeakDbtp): float
    {
        $targetLufs = (float) config('eh.audio_loudness_target_lufs', -18.0);
        $peakCeilingDbtp = (float) config('eh.audio_loudness_peak_ceiling_dbtp', -1.0);
        $maxBoostDb = max(0.0, (float) config('eh.audio_loudness_max_boost_db', 12.0));
        $maxCutDb = max(0.0, (float) config('eh.audio_loudness_max_cut_db', 24.0));

        $desiredGainDb = $targetLufs - $integratedLufs;
        $gainDb = $desiredGainDb;

        if ($truePeakDbtp !== null) {
            $peakLimitedGainDb = $peakCeilingDbtp - $truePeakDbtp;
            $gainDb = min($gainDb, $peakLimitedGainDb);
        }

        $gainDb = min($gainDb, $maxBoostDb);
        $gainDb = max($gainDb, -$maxCutDb);

        return round($gainDb, 3);
    }

    private function resolveLoudnessTimeoutSec(): int
    {
        $fromOption = $this->option('loudness-timeout-sec');
        if ($fromOption !== null && $fromOption !== '') {
            $n = (int) $fromOption;
            if ($n > 0) {
                return $n;
            }
        }

        $fromConfig = (int) config(
            'eh.audio_loudness_analysis_timeout_sec',
            self::DEFAULT_LOUDNESS_ANALYSIS_TIMEOUT_SEC
        );

        return max(1, $fromConfig);
    }
}
