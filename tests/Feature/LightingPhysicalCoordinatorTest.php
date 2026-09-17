<?php

namespace Tests\Feature;

use App\Lighting\Drivers\LightingDriver;
use App\Lighting\Drivers\MockLightingDriver;
use App\Lighting\LightingControl;
use App\Lighting\LightingCoordinator;
use App\Lighting\LightingPlanner;
use App\Lighting\LightingStore;
use App\Models\LightingState;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LightingPhysicalCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private string $epoch;

    private int $userId;

    private int $sequence = 0;

    private int $clock;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('lighting.driver', 'cloud');
        config()->set('lighting.white_fade_up_ms', 1000);
        config()->set('lighting.white_fade_down_ms', 1000);
        config()->set('lighting.scene_fade_out_ms', 500);
        config()->set('lighting.dark_hold_ms', 50);
        $this->clock = LightingStore::now();
        $this->userId = User::factory()->create()->id;
        config()->set('lighting.allowed_user_ids', [$this->userId]);
        $this->epoch = app(LightingControl::class)->control($this->userId, 'fixture-session', true, null)['controlEpoch'];
    }

    private function white(float $brightness = 1, float $temperature = 0, bool $settled = false): array
    {
        return [
            'driver' => 'cloud', 'mode' => 'white', 'brightnessPct' => $brightness,
            'temperaturePct' => $temperature, 'rgbSuppressed' => true,
            'quality' => $settled ? 'confirmed' : 'reported', 'outputSettled' => $settled,
            'completion' => $settled ? 'cloud_readback' : 'cloud_reported', 'physicalConfirmed' => false,
        ];
    }

    private function driver(array $initial, ?Closure $execute = null): object
    {
        $driver = new class($initial, $execute) implements LightingDriver
        {
            public array $commands = [];

            public function __construct(public array $stored, private ?Closure $executeCallback) {}

            public function capabilities(): array
            {
                return ['simulated' => false, 'safeSceneExit' => false];
            }

            public function readState(?array $lastObservation, int $now): array
            {
                if (($lastObservation['mode'] ?? null) === 'white'
                    && ($lastObservation['outputSettled'] ?? true) === false
                    && in_array($lastObservation['quality'] ?? null, ['commanded', 'unknown'], true)) {
                    return array_merge($lastObservation, ['quality' => 'commanded']);
                }

                return $this->stored;
            }

            public function execute(array $command, ?array $lastObservation, int $now): array
            {
                $this->commands[] = $command;
                if ($this->executeCallback !== null) {
                    return ($this->executeCallback)($command, $lastObservation, $now);
                }
                $settled = $command['operation'] !== 'white' || $command['commit'];
                $result = match ($command['operation']) {
                    'white' => [
                        'mode' => 'white', 'brightnessPct' => $command['brightnessPct'],
                        'temperaturePct' => $command['temperaturePct'], 'rgbSuppressed' => true,
                    ],
                    'dark_anchor' => ['mode' => 'white', 'brightnessPct' => 1.0, 'temperaturePct' => 0.0, 'rgbSuppressed' => true],
                    'scene' => [
                        'mode' => 'scene', 'color' => $command['color'], 'mythosSessionId' => $command['mythosSessionId'],
                        'sceneLevel' => 1.0, 'rgbSuppressed' => false,
                    ],
                    default => throw new \RuntimeException('unsupported_transition'),
                };
                $result += [
                    'driver' => 'cloud', 'quality' => $settled ? 'confirmed' : 'commanded',
                    'outputSettled' => $settled, 'physicalConfirmed' => false,
                    'completion' => $settled ? 'cloud_readback' : 'cloud_accepted',
                ];
                if ($settled) {
                    $this->stored = $result;
                }

                return $result;
            }
        };
        $this->app->instance(LightingDriver::class, $driver);

        return $driver;
    }

    private function send(array $target): void
    {
        $result = app(LightingControl::class)->intent($this->userId, 'fixture-session', [
            'intentId' => (string) Str::uuid(), 'controlEpoch' => $this->epoch,
            'clientSeq' => ++$this->sequence, 'target' => $target,
        ]);
        $this->assertTrue($result['accepted']);
    }

    private function tick(int $advance = 100): LightingState
    {
        $this->clock += $advance;
        app(LightingCoordinator::class)->tick($this->clock);

        return LightingState::findOrFail(1);
    }

    public function test_reported_dark_requires_an_explicit_confirmed_anchor_before_scene(): void
    {
        $driver = $this->driver($this->white());
        $this->send(['kind' => 'mythos', 'color' => 'blue', 'mythosSessionId' => 'fixture-mythos']);
        $state = $this->tick();
        $this->assertNull($state->dark_since_ms);
        $this->assertFalse(app(LightingPlanner::class)->isDark($state->observed));
        $state = $this->tick();
        $this->assertSame(['dark_anchor'], array_column($driver->commands, 'operation'));
        $this->assertNotSame($state->revision, $state->applied_revision);
        $state = $this->tick(10);
        $this->assertSame('dark', $state->stage);
        $state = $this->tick(100);
        $this->assertSame(['dark_anchor', 'scene'], array_column($driver->commands, 'operation'));
        $this->assertSame($state->revision, $state->applied_revision);
        $this->assertFalse($state->observed['physicalConfirmed']);
    }

    public function test_cloud_cannot_use_simulated_observation_as_physical_proof(): void
    {
        $driver = $this->driver(array_merge($this->white(), ['quality' => 'simulated']));
        $this->send(['kind' => 'mythos', 'color' => 'blue', 'mythosSessionId' => 'fixture-mythos']);
        $state = $this->tick();
        $this->assertSame('configuration_error', $state->error);
        $this->assertSame([], $driver->commands);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $this->assertNull($state->dark_since_ms);
    }

    public function test_cloud_configuration_cannot_silently_use_the_mock_driver(): void
    {
        $this->app->instance(LightingDriver::class, new MockLightingDriver);
        $this->send(['kind' => 'white', 'profile' => 'action']);
        $state = $this->tick();
        $this->assertSame('not_configured', $state->error);
        $this->assertNull($state->observed);
        $this->assertNotSame($state->revision, $state->applied_revision);
    }

    public function test_intermediate_white_is_commanded_and_only_final_frame_commits(): void
    {
        $driver = $this->driver($this->white());
        $this->send(['kind' => 'white', 'profile' => 'action']);
        $this->tick();
        $this->tick();
        $state = $this->tick(500);
        $this->assertSame([false, false], array_column($driver->commands, 'commit'));
        $this->assertSame('commanded', $state->observed['quality']);
        $this->assertFalse($state->observed['outputSettled']);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $state = $this->tick(500);
        $this->assertSame([false, false, true], array_column($driver->commands, 'commit'));
        $this->assertSame($state->revision, $state->applied_revision);
    }

    public function test_retarget_preserves_commanded_origin_without_forcing_a_white_dark_detour(): void
    {
        $driver = $this->driver($this->white(100, 33, true));
        $this->send(['kind' => 'mythos', 'color' => null, 'mythosSessionId' => 'fixture-mythos']);
        $this->tick();
        $this->tick();
        $state = $this->tick(500);
        $level = $state->observed['brightnessPct'];
        $this->send(['kind' => 'white', 'profile' => 'action']);
        $state = $this->tick();
        $this->assertFalse($state->color_barrier);
        $this->assertEquals($level, $state->observed['brightnessPct']);
        $state = $this->tick();
        $this->assertEquals($level, $state->observed['brightnessPct']);
        $this->assertNotContains('dark_anchor', array_column($driver->commands, 'operation'));
        $this->assertSame('fading_white_up', $state->stage);
    }

    public function test_unqualified_endpoint_acknowledgement_never_marks_white_applied(): void
    {
        config()->set('lighting.white_fade_up_ms', 0);
        $this->driver($this->white(), fn () => array_merge($this->white(100, 33), [
            'quality' => 'confirmed', 'completion' => 'cloud_accepted', 'outputSettled' => false,
        ]));
        $this->send(['kind' => 'white', 'profile' => 'action']);
        $this->tick();
        $state = $this->tick();
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $this->assertFalse($state->observed['outputSettled']);
        $this->assertTrue($state->color_barrier);
    }

    public function test_confirmed_but_wrong_endpoint_never_marks_white_applied(): void
    {
        config()->set('lighting.white_fade_up_ms', 0);
        $this->driver($this->white(), fn () => $this->white(90, 20, true));
        $this->send(['kind' => 'white', 'profile' => 'action']);
        $this->tick();
        $state = $this->tick();
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNotSame($state->revision, $state->applied_revision);
    }

    public function test_accepted_scene_without_qualified_readback_keeps_barrier_and_pending_revision(): void
    {
        config()->set('lighting.dark_hold_ms', 0);
        $this->driver($this->white(1, 0, true), fn ($command) => [
            'mode' => 'scene', 'color' => $command['color'], 'mythosSessionId' => $command['mythosSessionId'],
            'sceneLevel' => 1.0, 'quality' => 'commanded', 'outputSettled' => false, 'completion' => 'cloud_accepted',
        ]);
        $this->send(['kind' => 'mythos', 'color' => 'blue', 'mythosSessionId' => 'fixture-mythos']);
        $this->tick();
        $state = $this->tick();
        $this->assertSame('transport_timeout', $state->error);
        $this->assertTrue($state->color_barrier);
        $this->assertNotSame($state->revision, $state->applied_revision);
    }

    public function test_unsupported_rgb_exit_cannot_be_bypassed_with_zero_duration(): void
    {
        config()->set('lighting.scene_fade_out_ms', 0);
        $driver = $this->driver(['mode' => 'scene', 'sceneLevel' => 1.0, 'quality' => 'reported', 'outputSettled' => false]);
        $this->send(['kind' => 'white', 'profile' => 'action']);
        $this->tick();
        $state = $this->tick();
        $this->assertSame('unsupported_transition', $state->error);
        $this->assertSame([], $driver->commands);
        $this->assertTrue($state->color_barrier);
    }

    public function test_late_white_ack_keeps_only_unconfirmed_origin_for_the_new_goal(): void
    {
        $changed = false;
        $this->driver($this->white(), function ($command) use (&$changed) {
            if (! $changed && $command['brightnessPct'] > 1) {
                $changed = true;
                $this->send(['kind' => 'white', 'profile' => 'encounters']);
            }

            return array_merge($this->white($command['brightnessPct'], $command['temperaturePct']), [
                'quality' => 'commanded', 'completion' => 'cloud_accepted',
            ]);
        });
        $this->send(['kind' => 'white', 'profile' => 'action']);
        $this->tick();
        $this->tick();
        $state = $this->tick(500);
        $this->assertSame('encounters', $state->desired_target['profile']);
        $this->assertSame('unknown', $state->observed['quality']);
        $this->assertFalse($state->observed['outputSettled']);
        $this->assertGreaterThan(1, $state->observed['brightnessPct']);
        $this->assertNull($state->read_revision);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $level = $state->observed['brightnessPct'];
        $state = $this->tick();
        $this->assertSame('commanded', $state->observed['quality']);
        $this->assertEquals($level, $state->observed['brightnessPct']);
    }

    public function test_timed_out_old_write_invalidates_proof_without_discarding_new_target(): void
    {
        $this->driver($this->white(100, 33, true), function () {
            $this->send(['kind' => 'white', 'profile' => 'encounters']);
            throw new \RuntimeException('transport_timeout');
        });
        $this->send(['kind' => 'mythos', 'color' => null, 'mythosSessionId' => 'fixture-mythos']);
        $this->tick();
        $state = $this->tick();
        $this->assertSame('encounters', $state->desired_target['profile']);
        $this->assertNull($state->error);
        $this->assertNull($state->read_revision);
        $this->assertSame('unknown', $state->observed['quality']);
        $this->assertFalse($state->observed['outputSettled']);
        $this->assertNotSame($state->revision, $state->applied_revision);
    }
}
