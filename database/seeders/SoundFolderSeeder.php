<?php

namespace Database\Seeders;

use App\Domain\Pages;
use App\Models\SoundFolder;
use Illuminate\Database\Seeder;

class SoundFolderSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Pages::allFolders() as $slug => $meta) {
            SoundFolder::updateOrCreate(
                ['slug' => $slug],
                ['name' => $meta['name'], 'mode' => $meta['mode']],
            );
        }
    }
}
