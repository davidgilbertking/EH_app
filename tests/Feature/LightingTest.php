<?php

namespace Tests\Feature;

use App\Lighting\Drivers\LightingDriver;
use App\Lighting\Drivers\MockLightingDriver;
use App\Lighting\LightingControl;
use App\Lighting\LightingCoordinator;
use App\Lighting\LightingStore;
use App\Models\LightingState;
use App\Models\User;
use App\Models\UserState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LightingTest extends TestCase
{
    use RefreshDatabase;

    private string $epoch;

    private string $mythosSession;

    private int $sequence = 0;

    private int $clock;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('lighting.white_fade_down_ms', 1000);
        config()->set('lighting.white_fade_up_ms', 1000);
        config()->set('lighting.scene_fade_out_ms', 500);
        config()->set('lighting.dark_hold_ms', 50);
        config()->set('lighting.mock.delay_ms', 0);
        config()->set('lighting.mock.failure', null);
        $this->clock = LightingStore::now();
        $this->mythosSession = (string) Str::uuid();
    }

    private function link(): void
    {
        $this->actingAs(User::factory()->create());
        config()->set('lighting.allowed_user_ids', [auth()->id()]);
        $this->epoch = $this->postJson('/lighting/control', ['enabled' => true])->assertOk()->json('controlEpoch');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
    }

    private function target(?string $color = null): array
    {
        return ['kind' => 'mythos', 'mythosSessionId' => $this->mythosSession, 'color' => $color];
    }

    private function send(array $target, ?int $seq = null, ?string $intentId = null): TestResponse
    {
        return $this->postJson('/lighting/intents', [
            'intentId' => $intentId ?? (string) Str::uuid(), 'controlEpoch' => $this->epoch,
            'clientSeq' => $seq ?? ++$this->sequence, 'target' => $target,
        ]);
    }

    private function tick(int $advance = 100): void
    {
        $this->clock += $advance;
        app(LightingCoordinator::class)->tick($this->clock);
    }

    private function settle(): LightingState
    {
        for ($i = 0; $i < 100; $i++) {
            $this->tick();
            $state = LightingState::findOrFail(1);
            if ($state->applied_revision === $state->revision || $state->error !== null) {
                return $state;
            }
        }
        $this->fail('Lighting worker did not settle.');
    }

    private function commands(): array
    {
        return DB::table('lighting_events')->whereIn('operation', ['scene', 'dark_anchor', 'white', 'scene_dim'])
            ->orderBy('id')->get()->map(fn ($event) => ['operation' => $event->operation, 'stage' => $event->stage, 'target' => json_decode($event->target, true)])->all();
    }

    public function test_auth_and_read_only_status_and_mythos_page(): void
    {
        $this->getJson('/lighting/status')->assertUnauthorized();
        $this->postJson('/lighting/control', ['enabled' => true])->assertUnauthorized();
        $this->postJson('/lighting/intents', [])->assertUnauthorized();
        $this->get('/mythos')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        config()->set('lighting.allowed_user_ids', [auth()->id()]);
        $this->get('/mythos')->assertOk()->assertInertia(fn ($page) => $page->component('Mythos/Index'));
        $this->getJson('/lighting/status')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('simulated', true)
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('controlEpoch');
        $this->assertSame(0, LightingState::find(1)->revision);
        $this->assertDatabaseCount('lighting_events', 0);
    }

    public function test_full_color_target_can_arrive_before_entry_and_newer_action_wins(): void
    {
        $this->link();
        $this->send($this->target('green'), 2)->assertAccepted();
        $this->send($this->target(), 1)->assertOk()->assertJsonPath('accepted', false)->assertJsonPath('error', 'stale');
        $this->send(['kind' => 'white', 'profile' => 'action'], 4)->assertAccepted();
        $this->send($this->target('blue'), 3)->assertOk()->assertJsonPath('accepted', false);
        $state = $this->settle();
        $this->assertSame('white_active', $state->stage);
        $this->assertSame([], array_filter($this->commands(), fn ($command) => $command['operation'] === 'scene'));
    }

    public function test_retry_is_idempotent_and_changed_payload_is_rejected(): void
    {
        $this->link();
        $id = (string) Str::uuid();
        $revision = $this->send($this->target('blue'), 1, $id)->assertAccepted()->json('revision');
        $this->send($this->target('blue'), 1, $id)->assertAccepted()->assertJsonPath('duplicate', true)->assertJsonPath('acceptedRevision', $revision);
        $this->send($this->target('green'), 1, $id)->assertConflict()->assertJsonPath('error', 'intent_conflict');
        $this->assertSame($revision, LightingState::find(1)->revision);
        $this->assertDatabaseCount('lighting_intents', 1);
    }

    public function test_only_logical_allowlisted_targets_are_accepted(): void
    {
        $this->link();
        foreach ([
            ['kind' => 'white', 'profile' => 'arbitrary'],
            ['kind' => 'white', 'profile' => 'action', 'dp' => 24],
            ['kind' => 'mythos', 'color' => 'blue'],
            ['kind' => 'mythos', 'mythosSessionId' => $this->mythosSession],
            ['kind' => 'mythos', 'mythosSessionId' => $this->mythosSession, 'color' => 'red'],
        ] as $target) {
            $this->send($target)->assertUnprocessable();
        }
        $this->postJson('/lighting/control', ['enabled' => true, 'host' => 'localhost'])->assertUnprocessable();
        $this->assertDatabaseCount('lighting_intents', 0);
    }

    public function test_control_takeover_fences_old_epoch_and_status_never_discloses_token(): void
    {
        $this->link();
        $firstGeneration = $this->getJson('/lighting/status')->json('controlGeneration');
        $new = $this->postJson('/lighting/control', ['enabled' => true])->assertOk();
        $this->assertNotSame($firstGeneration, $new->json('controlGeneration'));
        $this->send($this->target('green'))->assertConflict()->assertJsonPath('error', 'control_lost');
        $this->postJson('/lighting/control', ['enabled' => false, 'controlEpoch' => $this->epoch])->assertConflict();
        $response = $this->getJson('/lighting/status')->assertJsonMissingPath('controlEpoch');
        $this->assertStringNotContainsString($new->json('controlEpoch'), $response->getContent());
        $this->assertStringNotContainsString('epoch_hash', $response->getContent());
    }

    public function test_mythos_waits_in_dark_until_color_and_repeated_scene_is_no_op(): void
    {
        $this->link();
        $this->send($this->target())->assertAccepted();
        $state = $this->settle();
        $this->assertSame('dark', $state->stage);
        $this->assertEquals(1, $state->observed['brightnessPct']);
        $this->assertTrue($state->observed['rgbSuppressed']);
        $this->assertFalse($state->color_barrier);
        $this->send($this->target('green'))->assertAccepted();
        $this->assertSame('scene_active', $this->settle()->stage);
        $count = count($this->commands());
        $this->send($this->target('green'))->assertAccepted();
        $this->settle();
        $this->assertCount($count, $this->commands());
    }

    public function test_early_colors_preserve_the_fade_and_only_latest_is_applied(): void
    {
        $this->link();
        $this->send($this->target())->assertAccepted();
        $this->tick();
        $this->tick();
        $startedAt = LightingState::find(1)->transition['startedAt'];
        $this->tick(300);
        $this->send($this->target('green'))->assertAccepted();
        $this->tick();
        $this->tick();
        $this->assertSame($startedAt, LightingState::find(1)->transition['startedAt']);
        $this->send($this->target('blue'))->assertAccepted();
        $this->send($this->target('yellow'))->assertAccepted();
        $this->settle();
        $scenes = array_values(array_filter($this->commands(), fn ($entry) => $entry['operation'] === 'scene'));
        $this->assertCount(1, $scenes);
        $this->assertSame('yellow', $scenes[0]['target']['color']);
        $operations = array_column($this->commands(), 'operation');
        $this->assertLessThan(array_search('scene', $operations), array_search('dark_anchor', $operations));
    }

    public function test_initial_white_fade_reverses_without_forced_dark(): void
    {
        $this->link();
        $this->send($this->target())->assertAccepted();
        $this->tick();
        $this->tick();
        $this->tick(400);
        $level = LightingState::find(1)->observed['brightnessPct'];
        $this->assertGreaterThan(1, $level);
        $this->assertLessThan(100, $level);
        $this->send(['kind' => 'white', 'profile' => 'action'])->assertAccepted();
        $this->tick();
        $this->tick();
        $this->assertEquals($level, LightingState::find(1)->observed['brightnessPct']);
        $this->assertSame('white_active', $this->settle()->stage);
        $this->assertNotContains('dark_anchor', array_column($this->commands(), 'operation'));
    }

    public static function interruptions(): array
    {
        return [[50], [250], [450]];
    }

    #[DataProvider('interruptions')]
    public function test_color_barrier_survives_interruption_and_worker_restart(int $elapsed): void
    {
        $this->link();
        $this->send($this->target('green'))->assertAccepted();
        $this->settle();
        $this->send($this->target('blue'))->assertAccepted();
        $this->tick();
        $this->tick();
        $this->tick($elapsed);
        $startedAt = LightingState::find(1)->transition['startedAt'];
        $this->assertTrue(LightingState::find(1)->color_barrier);
        $this->send(['kind' => 'white', 'profile' => 'encounters'])->assertAccepted();
        $this->tick(1);
        $this->assertSame($startedAt, LightingState::find(1)->transition['startedAt']);
        // A new service instance, as after a process restart, uses persisted state.
        $state = $this->settle();
        $this->assertSame('white_active', $state->stage);
        $this->assertEquals(90, $state->observed['brightnessPct']);
        $this->assertEquals(20, $state->observed['temperaturePct']);
        $commands = $this->commands();
        $green = array_search('scene', array_column($commands, 'operation'));
        $afterGreen = array_slice($commands, $green + 1);
        $this->assertNotContains('scene', array_column($afterGreen, 'operation'));
        $dark = array_search('dark_anchor', array_column($afterGreen, 'operation'));
        $white = array_search('white', array_column($afterGreen, 'operation'));
        $this->assertNotFalse($dark);
        $this->assertLessThan($white, $dark);
    }

    public function test_late_scene_acknowledgement_cannot_overwrite_newer_target_or_revision(): void
    {
        $this->link();
        $this->send($this->target())->assertAccepted();
        $this->settle();
        $callback = function () {
            $this->send(['kind' => 'white', 'profile' => 'action'])->assertAccepted();
        };
        $driver = new class($callback) extends MockLightingDriver
        {
            private bool $fired = false;

            public function __construct(private \Closure $callback) {}

            public function execute(array $command, ?array $lastObservation, int $now): array
            {
                $result = parent::execute($command, $lastObservation, $now);
                if ($command['operation'] === 'scene' && ! $this->fired) {
                    $this->fired = true;
                    ($this->callback)();
                }

                return $result;
            }
        };
        $this->app->instance(LightingDriver::class, $driver);
        $this->send($this->target('blue'))->assertAccepted();
        $this->tick();
        $this->tick();
        $state = LightingState::find(1);
        $this->assertSame('white', $state->desired_target['kind']);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $this->assertTrue($state->color_barrier);
        $state = $this->settle();
        $this->assertSame('white_active', $state->stage);
        $this->assertEquals(100, $state->observed['brightnessPct']);
        $this->assertDatabaseHas('lighting_events', ['operation' => 'late_acknowledgement']);
    }

    public function test_unlink_and_logout_cancel_pending_work_without_changing_simulated_output(): void
    {
        $this->link();
        $this->send($this->target('green'))->assertAccepted();
        $this->settle();
        $before = DB::table('lighting_mock_devices')->value('state');
        $this->postJson('/lighting/control', ['enabled' => false, 'controlEpoch' => $this->epoch])->assertOk();
        $this->tick();
        $this->assertSame($before, DB::table('lighting_mock_devices')->value('state'));
        $this->epoch = $this->postJson('/lighting/control', ['enabled' => true])->json('controlEpoch');
        $this->send($this->target('blue'))->assertAccepted();
        $this->post('/logout')->assertRedirect('/');
        $this->tick();
        $this->assertFalse(LightingState::find(1)->enabled);
        $this->assertSame($before, DB::table('lighting_mock_devices')->value('state'));
    }

    public function test_session_ownership_and_expiry_prevent_continuations(): void
    {
        $this->link();
        $user = auth()->user();
        app(LightingControl::class)->revokeSession($user->id, 'different-browser-session');
        $this->assertTrue(LightingState::find(1)->enabled);
        $this->send($this->target('blue'))->assertAccepted();
        LightingState::find(1)->update(['control_expires_ms' => $this->clock + 1]);
        $this->tick();
        $this->assertFalse(LightingState::find(1)->enabled);
        $this->assertSame([], $this->commands());
    }

    public function test_missing_worker_failure_and_unavailable_driver_report_honest_status(): void
    {
        $this->link();
        $this->send($this->target('blue'))->assertAccepted()->assertJsonPath('error', 'worker_unavailable');
        config()->set('lighting.mock.failure', 'transport_timeout');
        $this->tick();
        $this->getJson('/lighting/status')->assertJsonPath('error', 'transport_timeout')->assertJsonPath('stage', 'error');
        config()->set('lighting.mock.failure', null);
        config()->set('lighting.driver', 'unsupported_driver');
        $this->send(['kind' => 'white', 'profile' => 'action'])->assertAccepted();
        $this->tick();
        $this->getJson('/lighting/status')->assertJsonPath('error', 'not_configured')->assertJsonPath('simulated', false);
        $this->assertSame([], $this->commands());
    }

    public function test_external_scene_is_read_and_forces_a_completed_dark_anchor(): void
    {
        $this->link();
        $this->send(['kind' => 'white', 'profile' => 'action'])->assertAccepted();
        $this->settle();
        DB::table('lighting_mock_devices')->where('id', 1)->update(['state' => json_encode([
            'mode' => 'scene', 'color' => 'green', 'sceneLevel' => 1.0, 'rgbSuppressed' => false,
        ])]);
        $this->send(['kind' => 'white', 'profile' => 'encounters'])->assertAccepted();
        $this->settle();
        $commands = $this->commands();
        $dark = array_search('dark_anchor', array_column($commands, 'operation'));
        $white = array_search('white', array_column($commands, 'operation'));
        $this->assertNotFalse($dark);
        $this->assertLessThan($white, $dark);
    }

    public function test_csrf_protection_is_not_disabled_on_lighting_writes(): void
    {
        $this->link();
        $this->app['env'] = 'local';
        $this->postJson('/lighting/control', ['enabled' => true])->assertStatus(419);
    }

    public function test_new_mythos_session_does_not_reuse_previously_active_color(): void
    {
        $this->link();
        $this->send($this->target('green'))->assertAccepted();
        $this->settle();
        $count = count($this->commands());
        $this->send(['kind' => 'white', 'profile' => 'action'])->assertAccepted();
        $this->mythosSession = (string) Str::uuid();
        $this->send($this->target())->assertAccepted();
        $this->send($this->target('green'))->assertAccepted();
        $this->settle();
        $commands = array_slice($this->commands(), $count);
        $this->assertContains('dark_anchor', array_column($commands, 'operation'));
        $this->assertSame('scene', end($commands)['operation']);
    }

    public function test_external_dark_observation_starts_hold_instead_of_waiting_forever(): void
    {
        $this->link();
        DB::table('lighting_mock_devices')->where('id', 1)->update(['state' => json_encode([
            'mode' => 'white', 'brightnessPct' => 1.0, 'temperaturePct' => 0.0, 'rgbSuppressed' => true,
        ])]);
        $this->send($this->target('blue'))->assertAccepted();
        $this->assertSame('scene_active', $this->settle()->stage);
    }

    public function test_dark_hold_starts_when_delayed_driver_step_completes(): void
    {
        $this->link();
        config()->set('lighting.white_fade_down_ms', 0);
        config()->set('lighting.mock.delay_ms', 80);
        $this->send($this->target('blue'))->assertAccepted();
        $this->tick();
        $this->tick();
        $darkSince = LightingState::find(1)->dark_since_ms;
        $this->assertGreaterThanOrEqual($this->clock + 80, $darkSince);
        config()->set('lighting.mock.delay_ms', 0);
        $this->tick(20);
        $this->assertSame('dark', LightingState::find(1)->stage);
        $this->assertSame('scene_active', $this->settle()->stage);
    }

    public function test_legacy_and_explicit_blob_contexts_round_trip(): void
    {
        $this->link();
        $blobs = [
            ['id' => 'legacy', 'label' => 'Legacy', 'folderSlug' => 'action'],
            ['id' => 'contact', 'label' => 'Ancient', 'folderSlug' => 'ancient/cthulhu', 'gameContext' => 'encounters'],
        ];
        $this->post('/state/blobs', ['blobs' => $blobs])->assertStatus(303);
        $this->assertSame($blobs, UserState::where('user_id', auth()->id())->first()->blobs);
        $blobs[1]['gameContext'] = 'raw-command';
        $this->postJson('/state/blobs', ['blobs' => $blobs])->assertUnprocessable();
    }

    public function test_external_scene_edit_rebases_inflight_fade_from_actual_level(): void
    {
        $this->link();
        $this->send($this->target('green'))->assertAccepted();
        $this->settle();
        $this->send($this->target('blue'))->assertAccepted();
        $this->tick();
        $this->tick();
        $this->tick(100);
        $actual = json_decode(DB::table('lighting_mock_devices')->value('state'), true);
        $actual['sceneLevel'] = 0.1;
        DB::table('lighting_mock_devices')->where('id', 1)->update(['state' => json_encode($actual)]);
        $this->send($this->target('yellow'))->assertAccepted();
        $this->tick();
        $this->tick();
        $this->assertEquals(0.1, LightingState::find(1)->observed['sceneLevel']);
        $this->assertSame('yellow', $this->settle()->observed['color']);
    }

    public function test_control_receipt_identifies_the_generation_bound_to_its_secret(): void
    {
        $this->link();
        $first = $this->postJson('/lighting/control', ['enabled' => true])->assertOk();
        $this->assertSame($first->json('controlGeneration'), $first->json('controlEpochGeneration'));
        $second = $this->postJson('/lighting/control', ['enabled' => true])->assertOk();
        $this->assertNotSame($second->json('controlEpochGeneration'), $first->json('controlEpochGeneration'));
        $this->getJson('/lighting/status')->assertJsonMissingPath('controlEpochGeneration');
    }

    public function test_restart_reads_device_after_crash_between_scene_send_and_acknowledgement(): void
    {
        $this->link();
        $this->send($this->target())->assertAccepted();
        $this->settle();
        $this->send($this->target('blue'))->assertAccepted();
        $this->tick();
        $state = LightingState::find(1);
        $this->assertSame($state->revision, $state->read_revision);
        // Simulate the durable pre-send barrier plus device command, but no ack.
        $state->update(['color_barrier' => true, 'dark_since_ms' => null, 'stage' => 'applying_scene']);
        DB::table('lighting_mock_devices')->where('id', 1)->update(['state' => json_encode([
            'mode' => 'scene', 'color' => 'blue', 'sceneLevel' => 0.7, 'rgbSuppressed' => false,
            'mythosSessionId' => $this->mythosSession,
        ])]);
        app(LightingCoordinator::class)->recover();
        $this->assertNull(LightingState::find(1)->read_revision);
        $this->tick();
        $this->assertEquals(0.7, LightingState::find(1)->observed['sceneLevel']);
        $this->tick();
        $this->assertSame('fading_scene_down', LightingState::find(1)->stage);
        $this->assertEquals(0.7, LightingState::find(1)->observed['sceneLevel']);
        $this->assertSame('scene_active', $this->settle()->stage);
    }
}
