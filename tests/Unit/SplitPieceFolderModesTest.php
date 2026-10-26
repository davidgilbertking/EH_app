<?php

namespace Tests\Unit;

use App\Domain\Pages;
use App\Models\SoundFolder;
use PHPUnit\Framework\TestCase;

class SplitPieceFolderModesTest extends TestCase
{
    public function test_folders_with_split_long_pieces_start_at_a_random_position(): void
    {
        $folders = Pages::allFolders();
        foreach (['special/characters/akachi-onyele', 'special/characters/dexter-drake', 'special/characters/silas-marsh',
            'special/characters/vincent-lee', 'special/characters/william-yorick', 'special/characters/zoey-samaras',
            'special/disaster/location/otherworldly-rifts', 'special/characters/agatha-crane'] as $slug) {
            $this->assertSame(SoundFolder::MODE_RANDOM_POS_FADE, $folders[$slug]['mode'], $slug);
        }
        foreach (['special/characters/mark-harrigan', 'special/disaster/location/upheaval'] as $slug) {
            $this->assertSame(SoundFolder::MODE_FROM_START_NO_FADE, $folders[$slug]['mode'], $slug);
        }
    }
}
