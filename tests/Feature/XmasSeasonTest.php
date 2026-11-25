<?php

namespace Tests\Feature;

use App\Domain\XmasSeason;
use App\Models\SoundFolder;
use App\Models\SoundTrack;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class XmasSeasonTest extends TestCase
{
    use RefreshDatabase;

    public static function dates(): array
    {
        return [
            'before window' => ['2026-12-17 23:59', false],
            'window opens' => ['2026-12-18 00:00', true],
            'new year eve' => ['2026-12-31 23:00', true],
            'new year' => ['2027-01-01 00:00', true],
            'last day' => ['2027-01-14 23:59', true],
            'window closes' => ['2027-01-15 00:00', false],
            'summer' => ['2027-07-01 12:00', false],
        ];
    }

    #[DataProvider('dates')]
    public function test_season_window_in_moscow_time(string $moscowTime, bool $expected): void
    {
        config()->set('eh.xmas_season', null);
        $now = CarbonImmutable::parse($moscowTime, 'Europe/Moscow')->utc();

        $this->assertSame($expected, XmasSeason::isActive($now));
    }

    public function test_env_override_wins(): void
    {
        config()->set('eh.xmas_season', true);
        $this->assertTrue(XmasSeason::isActive(CarbonImmutable::parse('2027-07-01')));

        config()->set('eh.xmas_season', false);
        $this->assertFalse(XmasSeason::isActive(CarbonImmutable::parse('2026-12-31')));
    }

    public function test_action_picks_christmas_folder_in_season(): void
    {
        $this->seedActionFolders();
        config()->set('eh.xmas_season', true);

        $this->actingAs(User::factory()->create())
            ->getJson('/audio/folder/action-muted/random')
            ->assertOk()
            ->assertJsonPath('folderSlug', 'action-muted-xmas');
    }

    public function test_action_picks_regular_folder_off_season(): void
    {
        $this->seedActionFolders();
        config()->set('eh.xmas_season', false);

        $this->actingAs(User::factory()->create())
            ->getJson('/audio/folder/action/random')
            ->assertOk()
            ->assertJsonPath('folderSlug', 'action');
    }

    public function test_empty_christmas_folder_falls_back_to_regular(): void
    {
        $regular = SoundFolder::create(['slug' => 'action', 'name' => 'Action', 'mode' => SoundFolder::MODE_RANDOM_POS_FADE]);
        SoundFolder::create(['slug' => 'action-xmas', 'name' => 'Christmas Action', 'mode' => SoundFolder::MODE_RANDOM_POS_FADE]);
        SoundTrack::create(['sound_folder_id' => $regular->id, 'file_path' => 'action/a.m4a']);
        config()->set('eh.xmas_season', true);

        $this->actingAs(User::factory()->create())
            ->getJson('/audio/folder/action/random')
            ->assertOk()
            ->assertJsonPath('folderSlug', 'action');
    }

    public function test_manual_toggle_overrides_deployment_setting_and_changes_audio_folder(): void
    {
        $this->seedActionFolders();
        config()->set('eh.xmas_season', true);
        $this->actingAs(User::factory()->create());

        $offResponse = $this->from('/')->post('/xmas-mode/toggle')->assertRedirect('/');
        $offCookie = collect($offResponse->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === XmasSeason::MODE_COOKIE);
        $this->assertNotNull($offCookie);

        $this->withCredentials()->withUnencryptedCookie(XmasSeason::MODE_COOKIE, $offCookie->getValue())
            ->getJson('/audio/folder/action/random')
            ->assertOk()
            ->assertJsonPath('folderSlug', 'action');

        $onResponse = $this->from('/')->post('/xmas-mode/toggle')->assertRedirect('/');
        $onCookie = collect($onResponse->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === XmasSeason::MODE_COOKIE);
        $this->assertNotNull($onCookie);

        $this->withUnencryptedCookie(XmasSeason::MODE_COOKIE, $onCookie->getValue())
            ->getJson('/audio/folder/action-muted/random')
            ->assertOk()
            ->assertJsonPath('folderSlug', 'action-muted-xmas');
    }

    private function seedActionFolders(): void
    {
        foreach (['action', 'action-muted', 'action-xmas', 'action-muted-xmas'] as $slug) {
            $folder = SoundFolder::create(['slug' => $slug, 'name' => $slug, 'mode' => SoundFolder::MODE_RANDOM_POS_FADE]);
            SoundTrack::create(['sound_folder_id' => $folder->id, 'file_path' => "$slug/a.m4a"]);
        }
    }
}
