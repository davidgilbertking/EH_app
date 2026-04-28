<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PageRoutesTest extends TestCase
{
    use RefreshDatabase;

    public static function pageRoutes(): array
    {
        return [
            ['/'],
            ['/contacts'],
            ['/contacts/general'],
            ['/contacts/general/city'],
            ['/contacts/general/wilderness'],
            ['/contacts/general/sea'],
            ['/contacts/obstruction'],
            ['/contacts/big-city'],
            ['/contacts/outer-world'],
            ['/contacts/outer-world/past'],
            ['/contacts/outer-world/future'],
            ['/contacts/defeated'],
            ['/contacts/expedition'],
            ['/contacts/expedition/expeditions'],
            ['/contacts/add-map'],
            ['/contacts/add-map/antarctica'],
            ['/contacts/add-map/egypt'],
            ['/contacts/add-map/dreamlands'],
            ['/special'],
            ['/special/disaster'],
            ['/special/disaster/city'],
            ['/special/disaster/weather'],
            ['/special/disaster/location'],
            ['/special/investigators'],
            ['/special/ancient-ones'],
        ];
    }

    #[DataProvider('pageRoutes')]
    public function test_authenticated_pages_render(string $url): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user)->get($url)->assertOk();
    }

    public function test_set_ancient_one_persists_in_state(): void
    {
        $user = \App\Models\User::factory()->create();
        $ancient = \App\Models\AncientOne::firstOrCreate(
            ['slug' => 'cthulhu'],
            ['name' => 'Cthulhu', 'sort_order' => 0],
        );

        $this->actingAs($user)
            ->from('/contacts')
            ->post('/state/ancient-one', ['slug' => $ancient->slug])
            ->assertRedirect('/contacts');

        $this->assertDatabaseHas('user_states', [
            'user_id' => $user->id,
            'current_ancient_one_id' => $ancient->id,
        ]);
    }

    public function test_blob_save_and_clear_round_trip(): void
    {
        $user = \App\Models\User::factory()->create();
        $payload = [
            ['id' => 'blob-1', 'label' => 'Action', 'folderSlug' => 'action', 'mode' => null],
        ];

        $this->actingAs($user)
            ->from('/contacts')
            ->post('/state/blobs', ['blobs' => $payload])
            ->assertRedirect('/contacts');

        $this->assertDatabaseHas('user_states', [
            'user_id' => $user->id,
        ]);
        $this->assertCount(1, \App\Models\UserState::where('user_id', $user->id)->first()->blobs);

        $this->actingAs($user)
            ->from('/contacts')
            ->delete('/state/blobs')
            ->assertRedirect('/contacts');

        $this->assertSame([], \App\Models\UserState::where('user_id', $user->id)->first()->blobs);
    }

    public function test_audio_pick_404_when_folder_empty(): void
    {
        $user = \App\Models\User::factory()->create();
        \App\Models\SoundFolder::firstOrCreate(['slug' => 'action'], ['name' => 'Action']);

        $this->actingAs($user)
            ->getJson('/audio/folder/action/random')
            ->assertNotFound();
    }
}
