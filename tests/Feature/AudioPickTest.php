<?php

namespace Tests\Feature;

use App\Models\SoundFolder;
use App\Models\SoundTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AudioPickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function folder(string $slug, string $mode): SoundFolder
    {
        return SoundFolder::create(['slug' => $slug, 'name' => $slug, 'mode' => $mode]);
    }

    private function track(SoundFolder $folder, string $file, float $duration = 60): SoundTrack
    {
        return SoundTrack::create(['sound_folder_id' => $folder->id, 'file_path' => $folder->slug.'/'.$file,
            'duration_seconds' => $duration]);
    }

    private function pick(string $slug, ?int $after = null): int
    {
        $query = $after === null ? '' : '?after='.$after;

        return $this->getJson('/audio/folder/'.rawurlencode($slug).'/random'.$query)->assertOk()->json('trackId');
    }

    public function test_from_start_folder_never_begins_a_split_piece_with_a_later_part(): void
    {
        // Production 2026-10-25: Vincent Lee started on the 69 s tail of the concerto, then replayed it.
        $folder = $this->folder('special/characters/vincent-lee', SoundFolder::MODE_FROM_START_NO_FADE);
        $first = $this->track($folder, 'Concerto__part01.m4a', 1800);
        $this->track($folder, 'Concerto__part02.m4a', 69);

        for ($i = 0; $i < 40; $i++) {
            $this->assertSame($first->id, $this->pick($folder->slug));
        }
    }

    public function test_natural_end_continues_with_the_next_part_and_the_last_part_starts_over(): void
    {
        $folder = $this->folder('special/characters/zoey-samaras', SoundFolder::MODE_FROM_START_NO_FADE);
        $first = $this->track($folder, 'Mass__part01.m4a', 1800);
        $second = $this->track($folder, 'Mass__part02.m4a', 1145);

        $this->assertSame($second->id, $this->pick($folder->slug, $first->id));
        $this->assertSame($first->id, $this->pick($folder->slug, $second->id));
    }

    public function test_next_part_keeps_suffixes_after_the_part_number(): void
    {
        $folder = $this->folder('contacts/egypt/sahara-desert', SoundFolder::MODE_RANDOM_POS_FADE);
        $first = $this->track($folder, 'Wind__part01__eq15p01.m4a', 900);
        $second = $this->track($folder, 'Wind__part02__eq15p01.m4a', 900);

        $this->assertSame($second->id, $this->pick($folder->slug, $first->id));
    }

    public function test_random_position_folders_may_still_start_in_any_part(): void
    {
        $folder = $this->folder('contacts/dreamlands/underworld', SoundFolder::MODE_RANDOM_POS_FADE);
        $ids = [$this->track($folder, 'Cave__part01.m4a', 1800)->id, $this->track($folder, 'Cave__part02.m4a', 1800)->id];

        $seen = [];
        for ($i = 0; $i < 60 && count($seen) < 2; $i++) {
            $seen[$this->pick($folder->slug)] = true;
        }
        $this->assertEqualsCanonicalizing($ids, array_keys($seen));
    }

    public function test_after_from_another_folder_or_unsplit_track_falls_back_to_a_normal_pick(): void
    {
        $other = $this->folder('special/characters/dexter-drake', SoundFolder::MODE_FROM_START_NO_FADE);
        $foreign = $this->track($other, 'Moonlight__part01.m4a', 1800);
        $this->track($other, 'Moonlight__part02.m4a', 96);
        $folder = $this->folder('special/characters/agnes-baker', SoundFolder::MODE_FROM_START_NO_FADE);
        $single = $this->track($folder, 'Theme.m4a', 200);

        $this->assertSame($single->id, $this->pick($folder->slug, $foreign->id));
        $this->assertSame($single->id, $this->pick($folder->slug, $single->id));
        $this->assertSame($single->id, $this->pick($folder->slug, 999999));
    }

    public function test_from_start_folder_with_only_later_parts_indexed_still_plays(): void
    {
        $folder = $this->folder('special/disaster/location/otherworldly-rifts', SoundFolder::MODE_FROM_START_NO_FADE);
        $only = $this->track($folder, 'Gates__part02.m4a', 681);

        $this->assertSame($only->id, $this->pick($folder->slug));
    }
}
