<?php

namespace Tests\Feature;

use App\Lighting\CloudNativeCoordinator;
use App\Lighting\Drivers\NativeCloudLightingDriver;
use App\Lighting\Drivers\TuyaCloudException;
use App\Lighting\LightingControl;
use App\Lighting\LightingStore;
use App\Models\LightingState;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CloudNativeCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private NativeCloudCoordinatorFixture $driver;

    private CloudNativeCoordinator $coordinator;

    private int $now;

    private int $user;

    private string $epoch;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['lighting.driver' => 'cloud', 'lighting.cloud.native_transitions' => true,
            'lighting.white_fade_up_ms' => 12000, 'lighting.white_fade_down_ms' => 4000,
            'lighting.scene_fade_out_ms' => 4000, 'lighting.cloud.native_interruptions' => false,
            'lighting.dark_hold_ms' => 150, 'lighting.cloud.settle_margin_ms' => 400]);
        $this->now = LightingStore::now();
        $this->driver = new NativeCloudCoordinatorFixture(fn () => $this->now);
        $this->coordinator = new CloudNativeCoordinator(app(LightingStore::class), $this->driver);
        $this->user = User::factory()->create()->id;
        $this->epoch = app(LightingControl::class)->control($this->user, 'native-fixture', true, null)['controlEpoch'];
    }

    private function send(array $target): void
    {
        $response = app(LightingControl::class)->intent($this->user, 'native-fixture', [
            'intentId' => (string) Str::uuid(), 'controlEpoch' => $this->epoch,
            'clientSeq' => ++$this->sequence, 'target' => $target]);
        $this->assertTrue($response['accepted']);
    }

    private function white(string $profile = 'action'): array
    {
        return ['kind' => 'white', 'profile' => $profile];
    }

    private function mythos(?string $color = null): array
    {
        return ['kind' => 'mythos', 'color' => $color, 'mythosSessionId' => 'native-mythos'];
    }

    private function tick(int $advance = 100): LightingState
    {
        $this->now += $advance;
        $this->coordinator->tick($this->now);

        return LightingState::findOrFail(1);
    }

    private function until(Closure $condition, int $advance = 500): LightingState
    {
        for ($i = 0; $i < 140; $i++) {
            $state = $this->tick($advance);
            if ($condition($state)) {
                return $state;
            }
            if ($state->error !== null) {
                $this->fail('Unexpected native error: '.$state->error.' '.json_encode($state->native_effect));
            }
        }
        $this->fail('Native flow failed to complete within fixture ticks');
    }

    private function finish(): LightingState
    {
        return $this->until(fn ($state) => $state->applied_revision === $state->revision);
    }

    public function test_scene_exit_reaches_common_dark_before_latest_white_without_old_color(): void
    {
        $this->driver->scene('blue');
        $this->send($this->mythos('green'));
        $this->until(fn () => count($this->driver->writes) === 2);
        $this->send($this->white());
        $state = $this->finish();
        $operations = array_column($this->driver->writes, 'operation');
        $this->assertSame(['gradient', 'power', 'prepare_white', 'power', 'gradient', 'power', 'prepare_white', 'power'], $operations);
        $this->assertSame('dark', $this->driver->writes[2]['profile']);
        $this->assertSame('action', $this->driver->writes[6]['profile']);
        $this->assertSame(['onMs' => 12000, 'offMs' => 800], $this->driver->values[35]);
        $this->assertSame(1000, $this->driver->values[22]);
        $this->assertFalse($state->observed['physicalConfirmed']);
        $this->assertTrue($state->observed['outputSettled']);
    }

    public function test_native_on_wait_is_nonblocking_keeps_heartbeat_and_defers_new_target(): void
    {
        $this->driver->white('dark');
        $this->send($this->white());
        $this->until(fn () => count($this->driver->writes) === 4);
        $state = $this->tick(); // Readback starts the physical settlement wait.
        $this->assertNotSame($state->revision, $state->applied_revision);
        $this->send($this->mythos('green'));
        $start = microtime(true);
        $state = $this->tick(1000);
        $this->assertLessThan(0.2, microtime(true) - $start);
        $this->assertSame($this->now, $state->worker_seen_ms);
        $this->assertCount(4, $this->driver->writes);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $state = $this->finish();
        $this->assertSame('green', $state->observed['color']);
        $this->assertSame('scene', end($this->driver->writes)['operation']);
    }

    public function test_white_to_mythos_uses_native_off_and_holds_confirmed_dark_before_scene(): void
    {
        $this->send($this->mythos('blue'));
        $this->until(fn ($state) => $state->native_effect === null && $state->dark_since_ms !== null);
        $this->assertSame(['gradient', 'power', 'prepare_white', 'power'], array_column($this->driver->writes, 'operation'));
        $this->tick(10);
        $this->tick(10);
        $this->assertCount(4, $this->driver->writes);
        $state = $this->finish();
        $this->assertSame('blue', $state->observed['color']);
        $this->assertFalse($state->observed['physicalConfirmed']);
    }

    public function test_ambiguous_write_survives_new_intent_and_restart_then_only_readback_resolves_it(): void
    {
        $this->driver->scene('blue');
        $this->driver->throwAt = 1;
        $this->driver->applyOnFailure = true;
        $this->send($this->mythos('green'));
        $this->tick();
        $state = $this->tick();
        $id = $state->native_effect['pending']['id'];
        $this->assertSame('transport_timeout', $state->error);
        $this->send($this->white());
        $this->coordinator = new CloudNativeCoordinator(app(LightingStore::class), $this->driver);
        $this->coordinator->recover();
        $this->assertSame($id, LightingState::findOrFail(1)->native_effect['pending']['id']);
        $state = $this->tick();
        $this->assertNull($state->native_effect['pending']);
        $this->assertCount(1, $this->driver->writes);
        $this->finish();
        $this->assertCount(1, array_filter($this->driver->writes, fn ($write) => $write['operation'] === 'gradient' && $write['offMs'] === 4000));
    }

    public function test_unresolved_attempt_never_reposts_after_deadline_new_intent_or_restart(): void
    {
        $this->driver->throwAt = 1;
        $this->send($this->mythos('green'));
        $this->tick();
        $this->tick();
        $this->tick(16000);
        $this->send($this->white());
        $this->coordinator->recover();
        for ($i = 0; $i < 4; $i++) {
            $state = $this->tick(1000);
        }
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNotNull($state->native_effect['pending']);
        $this->assertCount(1, $this->driver->writes);
        $this->assertNotSame($state->revision, $state->applied_revision);
    }

    public function test_receipt_is_saved_after_revoke_but_no_subsequent_on_or_restore_is_sent(): void
    {
        $this->driver->afterIssue = function (array $descriptor) {
            if ($descriptor['operation'] === 'power' && ! $descriptor['on']) {
                app(LightingControl::class)->revokeSession($this->user, 'native-fixture');
            }
        };
        $this->send($this->mythos('blue'));
        $this->until(fn () => count($this->driver->writes) === 2);
        $state = LightingState::findOrFail(1);
        $this->assertFalse($state->enabled);
        $this->assertNotNull($state->native_effect['pending']['receipt']);
        for ($i = 0; $i < 8; $i++) {
            $this->tick(1000);
        }
        $this->assertCount(2, $this->driver->writes);
        $this->assertFalse($this->driver->values[20]);
    }

    public function test_retarget_after_preparing_old_white_never_switches_old_profile_on(): void
    {
        $this->driver->white('dark');
        $this->send($this->white('action'));
        $this->until(fn () => count($this->driver->writes) === 3);
        $this->send($this->white('encounters'));
        $this->finish();
        $onProfiles = array_column(array_filter($this->driver->writes, fn ($write) => $write['operation'] === 'power' && $write['on']), 'sourceBrightness');
        $this->assertNotContains(1000, $onProfiles);
        $this->assertSame(901, $this->driver->values[22]);
    }

    public function test_old_property_timestamps_cannot_confirm_a_matching_post(): void
    {
        $this->driver->staleTimes = true;
        $this->send($this->mythos());
        $this->tick();
        $this->tick();
        $state = $this->tick(16000);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertCount(1, $this->driver->writes);
        $this->assertNotNull($state->native_effect['pending']);
    }

    public function test_unknown_active_scene_is_not_switched_or_claimed_as_dark(): void
    {
        $this->driver->scene('unknown');
        $this->send($this->white());
        $state = $this->tick();
        $this->assertSame('unsupported_transition', $state->error);
        $this->assertSame([], $this->driver->writes);
        $this->assertNull($state->dark_since_ms);
    }

    public function test_white_to_white_has_no_blackout_and_endpoint_requires_fresh_confirmation(): void
    {
        $this->send($this->white('encounters'));
        $state = $this->finish();
        $operations = array_column($this->driver->writes, 'operation');
        $this->assertContains('realtime_white', $operations);
        $this->assertNotContains('power', $operations);
        $this->assertSame('endpoint_white', end($operations));
        $this->assertSame(901, $this->driver->values[22]);
        $this->assertSame('confirmed', $state->observed['quality']);
    }

    public function test_uncertain_realtime_frame_is_not_resolved_by_old_stored_white(): void
    {
        $this->driver->throwAt = 1;
        $this->send($this->white('encounters'));
        $this->tick();
        $this->tick();
        $this->send($this->white());
        $this->coordinator->recover();
        $state = $this->tick(16000);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertCount(1, $this->driver->writes);
        $this->assertNotNull($state->native_effect['pending']);
    }

    public function test_same_scene_in_same_mythos_session_does_not_restart_it(): void
    {
        $this->driver->white('dark');
        $this->send($this->mythos('blue'));
        $this->finish();
        $count = count($this->driver->writes);
        $this->send($this->mythos('blue'));
        $this->finish();
        $this->assertCount($count, $this->driver->writes);
    }

    public function test_native_gate_keeps_worker_readonly_until_explicit_activation(): void
    {
        config()->set('lighting.cloud.native_transitions', false);
        $this->send($this->white());
        $state = $this->tick();
        $this->assertSame([], $this->driver->writes);
        $this->assertSame(0, $this->driver->reads);
        $this->assertSame($this->now, $state->worker_seen_ms);
    }

    public function test_failed_initial_get_cannot_overwrite_newer_intent(): void
    {
        $this->send($this->white());
        $this->driver->beforeRead = function () {
            $this->driver->beforeRead = null;
            $this->send($this->mythos('green'));
            throw new TuyaCloudException('offline');
        };
        $state = $this->tick();
        $this->assertNull($state->error);
        $this->assertSame('green', $state->desired_target['color']);
        $this->assertSame('queued', $state->stage);
        $this->assertSame([], $this->driver->writes);
    }

    public function test_repeated_white_target_adopts_running_on_wait_without_restart(): void
    {
        $this->driver->white('dark');
        $this->send($this->white());
        $this->until(fn () => count($this->driver->writes) === 4);
        $this->tick();
        $this->send($this->white());
        $state = $this->finish();
        $this->assertCount(4, $this->driver->writes);
        $this->assertSame($state->revision, $state->applied_revision);
    }

    public function test_verified_interrupt_flag_can_exit_acknowledged_on_wait_through_dark(): void
    {
        config()->set('lighting.cloud.native_interruptions', true);
        $this->driver->white('dark');
        $this->send($this->white());
        $this->until(fn () => count($this->driver->writes) === 4);
        $this->tick();
        $started = $this->now;
        $this->send($this->mythos('green'));
        $this->until(fn () => count($this->driver->writes) === 6, 100);
        $this->assertLessThan(2000, $this->now - $started);
        $this->assertSame('power', $this->driver->writes[5]['operation']);
        $this->assertFalse($this->driver->writes[5]['on']);
        $state = $this->finish();
        $this->assertSame('green', $state->observed['color']);
    }

    public function test_interrupt_flag_never_discards_an_ambiguous_attempt(): void
    {
        config()->set('lighting.cloud.native_interruptions', true);
        $this->driver->white('dark');
        $this->driver->throwAt = 4;
        $this->send($this->white());
        $this->until(fn () => count($this->driver->writes) === 4);
        $this->send($this->mythos('green'));
        $state = $this->tick(20000);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertCount(4, $this->driver->writes);
        $this->assertNotNull($state->native_effect['pending']);
    }

    public function test_matching_gradient_is_not_rewritten_and_scene_uses_its_own_duration(): void
    {
        config()->set('lighting.scene_fade_out_ms', 2300);
        $this->driver->scene('blue');
        $this->driver->values[35] = ['onMs' => 800, 'offMs' => 2300];
        $this->send($this->mythos());
        $this->finish();
        $this->assertSame(['power', 'prepare_white', 'power'], array_column($this->driver->writes, 'operation'));
        $this->assertSame(2700, $this->driver->writes[0]['waitMs']);
    }

    public function test_white_endpoint_readback_still_waits_for_settlement_before_applied(): void
    {
        $this->send($this->white('encounters'));
        $this->until(fn () => in_array('endpoint_white', array_column($this->driver->writes, 'operation'), true));
        $state = $this->tick();
        $this->assertNotSame($state->revision, $state->applied_revision);
        $state = $this->tick(1000);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $this->finish();
    }

    public function test_known_prewrite_failure_is_not_an_ambiguous_latch_and_requires_new_intent(): void
    {
        $this->driver->prewriteFailure = true;
        $this->send($this->mythos());
        $this->tick();
        $state = $this->tick();
        $this->assertSame('configuration_error', $state->error);
        $this->assertNull($state->native_effect['pending']);
        $this->driver->prewriteFailure = false;
        $this->tick();
        $this->assertSame([], $this->driver->writes);
        $this->send($this->mythos());
        $this->finish();
        $this->assertCount(4, $this->driver->writes);
    }

    public function test_proven_prewrite_failure_cannot_block_a_new_intent_received_during_validation(): void
    {
        $this->driver->prewriteFailure = true;
        $this->driver->beforeIssue = function () {
            $this->driver->beforeIssue = null;
            $this->send($this->mythos('green'));
        };
        $this->send($this->mythos('blue'));
        $this->tick();
        $state = $this->tick();
        $this->assertNull($state->error);
        $this->assertNull($state->native_effect['pending']);
        $this->assertSame('green', $state->desired_target['color']);
        $this->assertSame([], $this->driver->writes);
        $this->driver->prewriteFailure = false;
        $state = $this->finish();
        $this->assertSame('green', $state->observed['color']);
    }
}

class NativeCloudCoordinatorFixture extends NativeCloudLightingDriver
{
    public array $values = [20 => true, 21 => 'white', 22 => 1000, 23 => 332, 25 => 'fixture-blue', 35 => ['onMs' => 500, 'offMs' => 600]];

    public array $times;

    public array $writes = [];

    public int $reads = 0;

    public ?int $throwAt = null;

    public bool $applyOnFailure = false;

    public bool $staleTimes = false;

    public ?Closure $afterIssue = null;

    public ?Closure $beforeRead = null;

    public ?Closure $beforeIssue = null;

    public bool $prewriteFailure = false;

    public function __construct(private Closure $clock)
    {
        $this->times = array_fill_keys(array_keys($this->values), ($clock)() - 100000);
    }

    public function profiles(): array
    {
        return ['dark' => ['brightness' => 10, 'temperature' => 0, 'brightnessPct' => 1.0, 'temperaturePct' => 0.0],
            'action' => ['brightness' => 1000, 'temperature' => 332, 'brightnessPct' => 100.0, 'temperaturePct' => 33.0],
            'encounters' => ['brightness' => 901, 'temperature' => 197, 'brightnessPct' => 90.0, 'temperaturePct' => 20.0]];
    }

    public function scenes(): array
    {
        return ['blue' => ['raw' => 'fixture-blue'], 'green' => ['raw' => 'fixture-green'], 'yellow' => ['raw' => 'fixture-yellow']];
    }

    public function white(string $profile): void
    {
        $this->values = array_replace($this->values, [20 => true, 21 => 'white',
            22 => $this->profiles()[$profile]['brightness'], 23 => $this->profiles()[$profile]['temperature']]);
    }

    public function scene(string $alias): void
    {
        $this->values = array_replace($this->values, [20 => true, 21 => 'scene', 25 => 'fixture-'.$alias]);
    }

    public function readSnapshot(): array
    {
        $this->reads++;
        if ($this->beforeRead !== null) {
            ($this->beforeRead)();
        }

        return ['values' => $this->values, 'times' => $this->times, 'serverTime' => ($this->clock)(), 'receivedAt' => ($this->clock)()];
    }

    public function issue(array $descriptor): array
    {
        if ($this->beforeIssue !== null) {
            ($this->beforeIssue)();
        }
        if ($this->prewriteFailure) {
            throw new TuyaCloudException('configuration_error');
        }
        $this->writes[] = array_diff_key($descriptor, ['sourceSnapshot' => true]) + ['sourceBrightness' => $this->values[22]];
        $failure = count($this->writes) === $this->throwAt;
        if (! $failure || $this->applyOnFailure) {
            $updates = match ($descriptor['operation']) {
                'gradient' => [35 => ['onMs' => $descriptor['onMs'], 'offMs' => $descriptor['offMs']]],
                'power' => [20 => $descriptor['on']],
                'prepare_white', 'endpoint_white' => [21 => 'white', 22 => $this->profiles()[$descriptor['profile']]['brightness'], 23 => $this->profiles()[$descriptor['profile']]['temperature']],
                'scene' => [21 => 'scene', 25 => $this->scenes()[$descriptor['color']]['raw']],
                'realtime_white' => [],
            };
            $this->values = array_replace($this->values, $updates);
            if (! $this->staleTimes) {
                foreach ($updates as $dp => $value) {
                    $this->times[$dp] = ($this->clock)();
                }
            }
        }
        if ($this->afterIssue !== null) {
            ($this->afterIssue)($descriptor);
        }
        if ($failure) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }

        return ['sentAt' => ($this->clock)(), 'acceptedAt' => ($this->clock)()];
    }

    public function observationFromSnapshot(array $snapshot, int $now): array
    {
        $values = $snapshot['values'];
        $result = ['driver' => 'cloud', 'mode' => $values[20] ? $values[21] : 'off', 'quality' => 'reported',
            'outputSettled' => false, 'completion' => 'cloud_reported', 'physicalConfirmed' => false,
            'rgbSuppressed' => $values[21] === 'white', 'observedAt' => $now];
        if ($values[21] === 'white') {
            foreach ($this->profiles() as $profile) {
                if ($values[22] === $profile['brightness'] && $values[23] === $profile['temperature']) {
                    $result += ['brightnessPct' => $profile['brightnessPct'], 'temperaturePct' => $profile['temperaturePct']];
                }
            }
        } else {
            $result += ['color' => str_replace('fixture-', '', $values[25]), 'sceneLevel' => 1.0];
        }

        return $result;
    }
}
