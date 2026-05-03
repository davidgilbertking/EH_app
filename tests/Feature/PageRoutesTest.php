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
            ['/encounters'],
            ['/encounters/general'],
            ['/encounters/general/city'],
            ['/encounters/general/wilderness'],
            ['/encounters/general/sea'],
            ['/encounters/restriction'],
            ['/encounters/named-cities'],
            ['/encounters/other-world'],
            ['/encounters/other-world/past'],
            ['/encounters/other-world/future'],
            ['/encounters/defeated'],
            ['/encounters/quest'],
            ['/encounters/quest/expedition'],
            ['/encounters/side-boards'],
            ['/encounters/side-boards/antarctica'],
            ['/encounters/side-boards/egypt'],
            ['/encounters/side-boards/dreamlands'],
            ['/other'],
            ['/other/disaster'],
            ['/other/disaster/city'],
            ['/other/disaster/weather'],
            ['/other/disaster/location'],
            ['/other/investigators'],
            ['/other/ancient-ones'],
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
            ->withSession(['_token' => 'test-token'])
            ->from('/encounters')
            ->post('/state/ancient-one', ['slug' => $ancient->slug, '_token' => 'test-token'])
            ->assertStatus(303)
            ->assertRedirect('/encounters');

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
            ->withSession(['_token' => 'test-token'])
            ->from('/encounters')
            ->post('/state/blobs', ['blobs' => $payload, '_token' => 'test-token'])
            ->assertStatus(303)
            ->assertRedirect('/encounters');

        $this->assertDatabaseHas('user_states', [
            'user_id' => $user->id,
        ]);
        $this->assertCount(1, \App\Models\UserState::where('user_id', $user->id)->first()->blobs);

        $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->from('/encounters')
            ->delete('/state/blobs', ['_token' => 'test-token'])
            ->assertStatus(303)
            ->assertRedirect('/encounters');

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
