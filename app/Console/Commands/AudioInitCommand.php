<?php

namespace App\Console\Commands;

use App\Models\SoundFolder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('audio:init')]
#[Description('Create an empty directory for every sound_folder under storage/app/private/audio/.')]
class AudioInitCommand extends Command
{
    public function handle(): int
    {
        $audioRoot = config('eh.audio_root', 'audio');
        $disk = Storage::disk('local');

        $created = 0;
        foreach (SoundFolder::orderBy('slug')->get() as $folder) {
            $rel = $audioRoot.'/'.$folder->slug;
            if (! $disk->exists($rel)) {
                $disk->makeDirectory($rel);
                $created++;
            }
            $disk->put(
                $rel.'/.keep',
                "Sound folder for: {$folder->name}\nMode: {$folder->mode}\nDrop audio files (mp3, m4a, ogg, wav, flac) directly into this directory, then run: php artisan audio:scan\n"
            );
        }

        $this->info("Audio folder scaffold ready under: ".$disk->path($audioRoot));
        $this->line("Created $created new directories. Existing ones left untouched.");

        return self::SUCCESS;
    }
}
