<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use App\Models\SoundFolder;
use App\Models\SoundTrack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('audio:scan {--folder= : Limit to a specific folder slug}')]
#[Description('Walk storage/app/private/audio/<slug>/ for each sound_folder and refresh sound_tracks rows.')]
class AudioScanCommand extends Command
{
    public function handle(): int
    {
        $audioRoot = config('eh.audio_root', 'audio');
        $disk = Storage::disk('local');
        $only = $this->option('folder');

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
            $existing = SoundTrack::where('sound_folder_id', $folder->id)
                ->pluck('file_path')
                ->all();
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
                SoundTrack::updateOrCreate(
                    ['sound_folder_id' => $folder->id, 'file_path' => $folder->slug.'/'.$relName],
                    ['duration_seconds' => $dur ? round((float) $dur, 3) : null],
                );
                $totalIndexed++;
            }
        }

        $this->info(sprintf(
            'Scanned %d folders. Found %d audio files; indexed %d tracks.',
            $folders->count(),
            $totalFound,
            $totalIndexed,
        ));

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
}
