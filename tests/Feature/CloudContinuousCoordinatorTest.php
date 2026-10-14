<?php

namespace Tests\Feature;

use App\Lighting\CloudContinuousCoordinator;
use App\Lighting\Drivers\NativeCloudLightingDriver;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use App\Lighting\LightingControl;
use App\Lighting\LightingStore;
use App\Models\LightingState;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloudContinuousCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private ContinuousCloudFixture $fixture;

    private CloudContinuousCoordinator $coordinator;

    private int $now;

    private int $user;

    private string $epoch;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['lighting.driver' => 'cloud', 'lighting.cloud.continuous_transitions' => true,
            'lighting.white_fade_down_ms' => 4000, 'lighting.white_fade_up_ms' => 12000,
            'lighting.scene_fade_in_ms' => 4000, 'lighting.scene_fade_out_ms' => 4000,
            'lighting.encounter_fade_in_ms' => 2500,
            'lighting.cloud.onboard_fades' => false, 'lighting.cloud.native_fade_timing_byte' => 30,
            'lighting.cloud.native_fade_wait_ms' => 5000,
            'lighting.cloud.white_up_curve' => 'perceptual',
            'lighting.cloud.frame_interval_ms' => 300, 'lighting.cloud.settle_margin_ms' => 400,
            'lighting.dark_hold_ms' => 150, 'lighting.curve' => 'linear']);
        $this->now = LightingStore::now();
        $this->fixture = new ContinuousCloudFixture(fn () => $this->now);
        $this->coordinator = $this->makeCoordinator();
        $this->user = User::factory()->create()->id;
        config()->set('lighting.allowed_user_ids', [$this->user]);
        $this->epoch = app(LightingControl::class)->control($this->user, 'continuous-fixture', true, null)['controlEpoch'];
    }

    private function makeCoordinator(): CloudContinuousCoordinator
    {
        return new CloudContinuousCoordinator(app(LightingStore::class), $this->fixture, new ContinuousSnapshotFixture($this->fixture));
    }

    public function test_removed_account_cannot_continue_an_inflight_transition(): void
    {
        $this->send($this->mythos());
        $this->until(fn () => count($this->fixture->writes) === 1);
        config()->set('lighting.allowed_user_ids', []);

        for ($i = 0; $i < 5; $i++) {
            $state = $this->tick(1000);
        }
        $this->assertFalse($state->enabled);
        $this->assertNull($state->owner_user_id);
        $this->assertNull($state->desired_target);
        $this->assertCount(1, $this->fixture->writes);
        $this->assertDatabaseHas('lighting_events', ['operation' => 'control_access_revoked']);
    }

    private function send(array $target): void
    {
        $response = app(LightingControl::class)->intent($this->user, 'continuous-fixture', [
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
        return ['kind' => 'mythos', 'color' => $color, 'mythosSessionId' => 'continuous-mythos'];
    }

    private function colour(string $profile = 'detained'): array
    {
        return ['kind' => 'colour', 'profile' => $profile];
    }

    private function tick(int $advance = 100): LightingState
    {
        $this->now += $advance;
        $before = count($this->fixture->writes);
        $this->coordinator->tick($this->now);
        $this->assertLessThanOrEqual(1, count($this->fixture->writes) - $before, 'A tick may issue at most one POST');

        return LightingState::findOrFail(1);
    }

    private function until(Closure $condition, int $advance = 100): LightingState
    {
        for ($i = 0; $i < 600; $i++) {
            $state = $this->tick($advance);
            if ($condition($state)) {
                return $state;
            }
            if ($state->error !== null) {
                $this->fail('Unexpected continuous error '.$state->error.' '.json_encode($state->native_effect));
            }
        }
        $this->fail('Continuous fixture did not reach condition');
    }

    private function finish(): LightingState
    {
        return $this->until(fn ($state) => $state->revision === $state->applied_revision);
    }

    private function frames(): array
    {
        return array_values(array_filter($this->fixture->writes, fn ($write) => $write['operation'] === 'realtime'));
    }

    private function commits(): array
    {
        return array_values(array_filter($this->fixture->writes, fn ($write) => $write['operation'] === 'commands'));
    }

    private function colourHolds(): array
    {
        return array_values(array_filter($this->fixture->writes, fn ($write) => $write['operation'] === 'static_colour'));
    }

    private function nativePrograms(): array
    {
        return array_values(array_filter($this->commits(), fn ($write) => count($write['commands'][0]['value']['scene_units'] ?? []) === 2));
    }

    public static function colourFades(): array
    {
        return ['software' => [false], 'onboard' => [true]];
    }

    #[DataProvider('colourFades')]
    public function test_encounter_colour_uses_direct_minima_and_fixed_software_rise_even_with_onboard_enabled(bool $onboard): void
    {
        config()->set('lighting.cloud.onboard_fades', $onboard);
        $this->send($this->colour());
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertSame(2500, $state->native_effect['fade']['duration']);
        $this->assertSame('colour', $state->native_effect['fade']['purpose']);
        $this->assertSame([], $this->frames(), 'Dimming and hue selection use direct endpoints, never a gradient');
        $this->assertTrue($state->native_effect['encounterTransition']);
        $state = $this->finish();
        $expected = $this->fixture->colours()['detained'] + ['bright' => 0, 'temperature' => 0];
        $this->assertSame('colour', $state->stage);
        $this->assertSame('detained', $state->observed['profile']);
        $this->assertSame($this->fixture->colours()['detained'], $state->observed['hsv']);
        $this->assertFalse($state->observed['scenePhaseApproximate']);
        $this->assertFalse($state->observed['physicalConfirmed']);
        $this->assertSame(array_replace($expected, ['v' => 10]), $this->colourHolds()[0]['channels']);
        $this->assertSame($expected, $this->colourHolds()[1]['channels']);
        $this->assertSame([10], array_values(array_filter(array_column($this->commits(), 'brightness'), fn ($v) => $v !== null)));
        $rgb = array_values(array_filter($this->frames(), fn ($frame) => $frame['channels']['v'] > 0));
        foreach ($rgb as $frame) {
            $this->assertSame(25, $frame['channels']['h']);
            $this->assertSame(853, $frame['channels']['s']);
            $this->assertGreaterThanOrEqual(10, $frame['channels']['v']);
            $this->assertSame(0, $frame['channels']['bright']);
        }
        $this->assertSame([], $this->nativePrograms());
        $this->assertSame($expected, end($rgb)['channels']);
        $writes = count($this->fixture->writes);
        $this->coordinator->recover();
        $this->tick(10000);
        $this->assertCount($writes, $this->fixture->writes);
    }

    #[DataProvider('colourFades')]
    public function test_static_colour_switch_dims_old_hue_to_one_percent_before_changing_hue(bool $onboard): void
    {
        config()->set('lighting.cloud.onboard_fades', $onboard);
        $this->fixture->values[21] = 'colour';
        $this->fixture->values[24] = '0019035503e8'; // Exact captured detained HSV.
        $this->send($this->colour('underworld'));
        $state = $this->finish();
        $this->assertSame('underworld', $state->observed['profile']);
        $this->assertSame(['h' => 250, 's' => 670, 'v' => 150], $state->observed['hsv']);
        $this->assertCount(3, $this->colourHolds());
        $this->assertSame([], $this->commits(), 'Colour-to-colour must never enter white mode');
        foreach ($this->frames() as $frame) {
            $this->assertSame(0, $frame['channels']['bright']);
        }
        $oldMinimum = $this->colourHolds()[0];
        $this->assertSame(25, $oldMinimum['channels']['h']);
        $this->assertSame(10, $oldMinimum['channels']['v']);
        $anchor = $this->colourHolds()[1];
        $this->assertSame(250, $anchor['channels']['h']);
        $this->assertSame(10, $anchor['channels']['v']);
        $this->assertSame(0, $anchor['channels']['bright']);
        $this->assertLessThan($anchor['at'], $oldMinimum['at']);
        $this->assertSame([], $this->nativePrograms());
        $this->assertSame([], array_values(array_filter($this->frames(), fn ($frame) => $frame['channels']['h'] === 25)));
        $this->send($this->white());
        $state = $this->finish();
        $this->assertSame('white', $state->stage);
        $this->assertTrue($this->fixture->values[20]);
        $this->assertSame([1000, 332], [$this->fixture->values[22], $this->fixture->values[23]]);
    }

    public function test_retarget_during_colour_rise_never_commits_superseded_colour(): void
    {
        $this->fixture->white('dark');
        $this->send($this->colour());
        $state = $this->until(fn ($state) => ($state->native_effect['fade']['purpose'] ?? null) === 'colour'
            && $state->native_effect['output']['channels']['v'] > 10 && $state->native_effect['pending'] === null);
        $this->send($this->colour('underworld'));
        $this->coordinator->recover();
        $state = $this->finish();
        $this->assertSame('underworld', $state->observed['profile']);
        $endpoints = array_values(array_filter($this->colourHolds(), fn ($hold) => $hold['channels']['v'] > 10));
        $this->assertCount(1, $endpoints);
        $this->assertSame(250, $endpoints[0]['channels']['h']);
        $this->assertTrue($state->native_effect['encounterTransition']);
        $this->assertSame(2500, $state->native_effect['fade']['duration']);
        $this->assertSame([], $this->commits(), 'Retargeting and restart must not add a white bridge');
    }

    private function droppedColourEndpoint(): LightingState
    {
        $this->fixture->white('dark');
        $this->send($this->colour('underworld'));
        $this->until(fn ($state) => $state->native_effect['phase'] === 'settle'
            && $state->native_effect['fade']['purpose'] === 'colour');
        $this->fixture->discardedWrites[] = count($this->fixture->writes) + 1;

        return $this->until(fn ($state) => ($state->native_effect['pending']['descriptor']['profile'] ?? null) === 'underworld');
    }

    public function test_acknowledged_but_unreported_colour_endpoint_retries_once_after_restart(): void
    {
        $this->droppedColourEndpoint();
        $writes = count($this->fixture->writes);
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->tick(16000);
        $this->assertCount($writes + 1, $this->fixture->writes);
        $this->assertSame(1, $state->native_effect['pending']['descriptor']['readbackRetries']);
        $state = $this->finish();
        $this->assertNull($state->error);
        $this->assertSame('underworld', $state->observed['profile']);
        $this->assertCount(3, $this->colourHolds());
        $this->assertDatabaseHas('lighting_events', ['operation' => 'continuous_endpoint_retry']);
    }

    public function test_newest_target_replaces_expired_acknowledged_endpoint_without_replaying_old_colour(): void
    {
        $this->droppedColourEndpoint();
        $writes = count($this->fixture->writes);
        $this->send($this->white());
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->tick(16000);
        $this->assertNull($state->native_effect['pending']);
        $this->assertCount($writes, $this->fixture->writes);
        $this->assertTrue($state->native_effect['encounterTransition']);
        $state = $this->finish();
        $this->assertNull($state->error);
        $this->assertSame($this->white(), $state->native_effect['target']);
        $this->assertSame(2500, $state->native_effect['fade']['duration']);
        $this->assertCount(2, $this->colourHolds(), 'No resend of the obsolete colour');
        $this->assertDatabaseHas('lighting_events', ['operation' => 'continuous_endpoint_unconfirmed']);
    }

    public function test_retry_limit_preserves_error_but_does_not_block_a_later_gesture_forever(): void
    {
        $this->droppedColourEndpoint();
        $this->fixture->discardedWrites[] = count($this->fixture->writes) + 1;
        $this->tick(16000);
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->tick(16000);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNull($state->native_effect['pending']);
        $this->assertNotNull($state->native_effect['unconfirmedEndpoint']);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $writes = count($this->fixture->writes);
        $reads = $this->fixture->reads;
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $this->tick(60000);
        $this->assertCount($writes, $this->fixture->writes);
        $this->assertSame($reads, $this->fixture->reads);
        $this->send($this->white());
        $state = $this->finish();
        $this->assertNull($state->error);
        $this->assertSame($this->white(), $state->native_effect['target']);
    }

    public function test_revocation_prevents_retry_of_an_acknowledged_endpoint(): void
    {
        $this->droppedColourEndpoint();
        app(LightingControl::class)->revokeSession($this->user, 'continuous-fixture');
        $writes = count($this->fixture->writes);
        $state = $this->tick(16000);
        $this->assertFalse($state->enabled);
        $this->assertNull($state->native_effect['pending']);
        $this->tick(16000);
        $this->assertCount($writes, $this->fixture->writes);
    }

    public function test_external_device_change_prevents_automatic_endpoint_retry(): void
    {
        $this->droppedColourEndpoint();
        $this->fixture->white('action');
        $writes = count($this->fixture->writes);
        $state = $this->tick(16000);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNull($state->native_effect['pending']);
        $this->assertCount($writes, $this->fixture->writes);
        $this->assertSame(1000, $this->fixture->values[22]);
    }

    public function test_new_intent_during_confirmation_read_cannot_retry_an_obsolete_endpoint(): void
    {
        $this->droppedColourEndpoint();
        $writes = count($this->fixture->writes);
        $this->fixture->beforeRead = function () {
            $this->fixture->beforeRead = null;
            $this->send($this->white('future-1'));
        };
        $state = $this->tick(16000);
        $this->assertCount($writes, $this->fixture->writes);
        $this->assertSame($this->white('future-1'), $state->native_effect['target']);
        $state = $this->finish();
        $this->assertNull($state->error);
        $this->assertSame(200, $this->fixture->values[22]);
    }

    public function test_unknown_endpoint_readback_is_throttled_then_replanned_from_fresh_report_after_deadline(): void
    {
        $this->fixture->scene('blue');
        $this->fixture->throwAt = 1;
        $this->send($this->white());
        $this->until(fn () => count($this->colourHolds()) === 1);
        $this->tick(5000);
        $reads = $this->fixture->reads;
        $this->tick(100);
        $this->assertSame($reads, $this->fixture->reads);
        $this->tick(500);
        $this->assertSame($reads + 1, $this->fixture->reads);
        $this->assertCount(1, $this->fixture->writes, 'No resend while the lost POST may still land');

        $state = $this->tick(11000);
        $this->assertNull($state->error);
        $this->assertNull($state->native_effect['pending']);
        $this->assertCount(1, $this->fixture->writes);
        $this->assertDatabaseHas('lighting_events', ['operation' => 'continuous_endpoint_unconfirmed']);
        $state = $this->finish();
        $this->assertSame($this->white(), $state->native_effect['target']);
        $this->assertSame([20 => true, 21 => 'white', 22 => 1000, 23 => 332],
            array_intersect_key($this->fixture->values, array_flip([20, 21, 22, 23])));
    }

    public function test_colour_to_mythos_darkness_still_selects_white_minimum(): void
    {
        $this->fixture->values[21] = 'colour';
        $this->fixture->values[24] = '0019035503e8';
        $this->send($this->mythos());
        $state = $this->finish();
        $this->assertSame('dark', $state->stage);
        $this->assertSame('white', $this->fixture->values[21]);
        $this->assertSame(10, $this->fixture->values[22]);
        $this->assertSame([], $this->frames());
        $this->assertCount(1, $this->colourHolds());
    }

    public function test_late_confirmation_clears_white_endpoint_timeout_after_restart(): void
    {
        $this->fixture->white('dark');
        $this->send($this->white());
        $this->until(fn ($state) => $state->native_effect['phase'] === 'settle'
            && $state->native_effect['fade']['purpose'] === 'white');
        $this->fixture->throwAt = count($this->fixture->writes) + 1;
        $this->fixture->applyBeforeThrow = true;
        $state = $this->until(fn ($state) => ($state->native_effect['pending']['descriptor']['profile'] ?? null) === 'action');
        $this->assertSame('transport_timeout', $state->error);
        $writes = count($this->fixture->writes);
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->finish();
        $this->assertNull($state->error);
        $this->assertCount($writes, $this->fixture->writes);
    }

    public function test_unknown_colour_endpoint_post_is_reconciled_once_by_fresh_exact_readback(): void
    {
        $this->fixture->white('dark');
        $this->send($this->colour());
        $this->until(fn ($state) => $state->native_effect['phase'] === 'settle'
            && $state->native_effect['fade']['purpose'] === 'colour');
        $this->fixture->throwAt = count($this->fixture->writes) + 1;
        $this->fixture->applyBeforeThrow = true;
        $state = $this->until(fn () => count($this->colourHolds()) === 2);
        $this->assertSame('transport_timeout', $state->error);
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->finish();
        $this->assertNull($state->error);
        $this->assertSame('detained', $state->observed['profile']);
        $this->assertCount(2, $this->colourHolds());
    }

    public function test_static_colour_can_enter_and_leave_existing_mythos_breathing_scenes(): void
    {
        $this->fixture->values[21] = 'colour';
        $this->fixture->values[24] = '00fa029e0096';
        $this->send($this->mythos('blue'));
        $state = $this->finish();
        $this->assertSame('scene', $state->stage);
        $this->assertSame('blue', $state->observed['color']);
        $this->assertSame($this->fixture->scenes()['blue']['raw'], $this->fixture->values[25]);
        $this->assertSame(2500, $state->native_effect['fade']['duration']);
        $this->send($this->colour());
        $state = $this->finish();
        $this->assertSame('detained', $state->observed['profile']);
        $this->assertFalse($state->observed['scenePhaseApproximate']);
        $this->assertSame(2500, $state->native_effect['fade']['duration']);
        $whiteCommands = array_filter($this->commits(), fn ($write) => $write['brightness'] !== null);
        $this->assertSame([], $whiteCommands, 'RGB encounter/Mythos switches must remain in RGB');
    }

    public function test_completed_colour_adopts_external_white_on_next_intent_and_reaches_low_white_captures(): void
    {
        $this->send($this->colour());
        $state = $this->finish();
        $oldId = $state->native_effect['id'];
        $this->fixture->white('future-1');
        $writes = count($this->fixture->writes);
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $this->tick(1000);
        $this->assertCount($writes, $this->fixture->writes, 'Restart does not overwrite manual light');
        $this->send($this->white('future-6'));
        $state = $this->tick();
        $this->assertNotSame($oldId, $state->native_effect['id']);
        $this->assertSame('white', $state->native_effect['output']['kind']);
        $this->assertSame(200, $state->native_effect['output']['channels']['bright']);
        $this->assertTrue($state->native_effect['encounterTransition']);
        $this->assertCount($writes, $this->fixture->writes, 'Rebase itself only reads');
        $state = $this->finish();
        $this->assertSame('white', $state->stage);
        $this->assertSame([150, 151], [$this->fixture->values[22], $this->fixture->values[23]]);

        $this->send($this->colour('underworld'));
        $this->finish();
        $this->send($this->white('future-1'));
        $this->finish();
        $this->assertSame([200, 1000], [$this->fixture->values[22], $this->fixture->values[23]]);
    }

    public function test_missing_colour_capture_is_rejected_before_the_first_write(): void
    {
        $this->send($this->colour('absent-capture'));
        $state = $this->until(fn ($state) => $state->error !== null);
        $this->assertSame('preset_not_configured', $state->error);
        $this->assertSame([], $this->fixture->writes);
    }

    public static function encounterWhites(): array
    {
        return [['future-1', false], ['future-1', true], ['future-6', false], ['future-6', true]];
    }

    #[DataProvider('encounterWhites')]
    public function test_white_encounter_entry_and_exit_change_temperature_at_minimum_before_fixed_rise(string $profile, bool $onboard): void
    {
        config()->set('lighting.cloud.onboard_fades', $onboard);
        $this->send($this->white($profile));
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $fade = $state->native_effect['fade'];
        $temperature = $this->fixture->profiles()[$profile]['temperature'];
        $this->assertSame(2500, $fade['duration'], 'Low target brightness must not shorten the encounter rise');
        $this->assertSame(10, $fade['from']['bright']);
        $this->assertSame($temperature, $fade['from']['temperature']);
        $this->assertSame($temperature, $fade['to']['temperature']);
        $this->assertSame([10, 10], array_column($this->commits(), 'brightness'));
        $this->assertSame([], $this->frames());
        $this->assertSame([], $this->nativePrograms());
        $state = $this->finish();
        $this->assertSame('white', $state->stage);
        $this->assertCount(3, $this->commits(), 'Exactly one dim, one temperature anchor, and one final endpoint');
        $writes = count($this->fixture->writes);
        $this->send($this->white($profile));
        $this->finish();
        $this->assertCount($writes, $this->fixture->writes, 'Repeating a completed target never dips');

        $this->send($this->white());
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertSame(2500, $state->native_effect['fade']['duration']);
        $this->assertSame(10, $state->native_effect['fade']['from']['bright']);
        $this->assertSame(332, $state->native_effect['fade']['from']['temperature']);
        $this->finish();
        $this->assertSame([], $this->nativePrograms());

        config()->set('lighting.cloud.onboard_fades', false);
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertFalse($state->native_effect['encounterTransition']);
        $this->assertSame(4000, $state->native_effect['fade']['duration'], 'A completed exit does not alter later ordinary Mythos transitions');
    }

    public function test_encounter_origin_survives_dark_intermediate_retarget_and_worker_restart(): void
    {
        $this->fixture->white('future-6');
        $this->send($this->white());
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'plan'
            && ($state->native_effect['encounterMinimum'] ?? false));
        $this->assertSame(10, $state->native_effect['output']['channels']['bright']);
        $this->send($this->mythos('blue'));
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertTrue($state->native_effect['encounterTransition']);
        $this->assertSame('scene', $state->native_effect['fade']['purpose']);
        $this->assertSame(2500, $state->native_effect['fade']['duration']);
        $this->assertSame(10, $state->native_effect['fade']['from']['v']);
        $this->assertSame([], $this->nativePrograms());
        $state = $this->finish();
        $this->assertSame('scene', $state->stage);
    }

    public function test_manual_normal_white_override_discards_a_completed_encounter_policy(): void
    {
        $this->send($this->colour());
        $this->finish();
        $this->fixture->white('action');
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertFalse($state->native_effect['encounterTransition']);
        $this->assertSame(4000, $state->native_effect['fade']['duration']);
    }

    public function test_encounter_retarget_interrupts_qualified_native_wait_with_direct_minimum(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->fixture->white('dark');
        $this->send($this->mythos('blue'));
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'onboard_wait');
        $oldDeadline = $state->native_effect['notBefore'];
        $before = count($this->fixture->writes);
        $this->send($this->white('future-1'));
        $state = $this->until(fn () => count($this->fixture->writes) > $before);
        $this->assertLessThan($oldDeadline, $this->now);
        $last = end($this->fixture->writes);
        $this->assertSame('static_colour', $last['operation']);
        $this->assertSame(199, $last['channels']['h']);
        $this->assertSame(10, $last['channels']['v']);
        $state = $this->finish();
        $this->assertSame(2500, $state->native_effect['fade']['duration']);
        $this->assertCount(1, $this->nativePrograms(), 'No new native program is issued for the encounter transition');
        $this->assertSame([200, 1000], [$this->fixture->values[22], $this->fixture->values[23]]);
    }

    public function test_onboard_dark_sends_one_target_only_program_and_waits_before_recording_its_output(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'onboard_wait');
        $this->assertSame(1000, $state->native_effect['output']['channels']['bright']);
        $units = $this->nativePrograms()[0]['commands'][0]['value']['scene_units'];
        $this->assertSame($units[0], $units[1]);
        $this->assertSame([10, 10], array_column($units, 'bright'));
        $this->assertSame([0, 0], array_column($units, 'v'));
        $until = $state->native_effect['onboard']['until'];
        $this->assertSame($this->fixture->values[25], $state->native_effect['onboard']['programRaw']);
        $this->assertSame($this->fixture->values[25], $state->native_effect['onboard']['proof']['values'][25]);
        $this->assertSame($this->nativePrograms()[0]['commands'], $state->native_effect['onboard']['descriptor']['commands']);
        $this->assertNotNull($state->native_effect['onboard']['receipt']);
        $state = $this->tick($until - $this->now - 1);
        $this->assertSame(1000, $state->native_effect['output']['channels']['bright']);
        $this->assertCount(1, $this->fixture->writes);
        $state = $this->finish();
        $this->assertCount(1, $this->nativePrograms());
        $this->assertSame([], $this->frames());
        $this->assertSame([10], array_values(array_filter(array_column($this->commits(), 'brightness'), fn ($v) => $v !== null)));
        $this->assertGreaterThanOrEqual($until, end($this->fixture->writes)['at']);
        $this->assertFalse($state->observed['physicalConfirmed']);
    }

    public function test_onboard_scene_keeps_single_minimum_rgb_anchor_then_one_full_target_program(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->fixture->white('dark');
        $this->send($this->mythos('blue'));
        $state = $this->finish();
        $this->assertCount(1, $this->frames());
        $this->assertSame(['h' => 199, 's' => 1000, 'v' => 10, 'bright' => 0, 'temperature' => 0], $this->frames()[0]['channels']);
        $this->assertCount(1, $this->nativePrograms());
        $program = $this->nativePrograms()[0];
        $units = $program['commands'][0]['value']['scene_units'];
        $this->assertSame($units[0], $units[1]);
        $this->assertSame([1000, 1000], array_column($units, 'v'));
        $this->assertSame([0, 0], array_column($units, 'bright'));
        $this->assertGreaterThanOrEqual(400, $program['at'] - $this->frames()[0]['at']);
        $last = end($this->fixture->writes);
        $this->assertSame($this->fixture->scenes()['blue']['value'], $last['commands'][0]['value']);
        $this->assertGreaterThanOrEqual(5000, $last['at'] - $program['at']);
        $this->assertFalse($state->observed['physicalConfirmed']);
    }

    public function test_onboard_rgb_down_holds_only_rgb_minimum_then_bridges_and_preserves_perceptual_white_up(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->fixture->scene('blue');
        $this->send($this->white());
        $this->finish();
        $this->assertCount(1, $this->nativePrograms());
        $program = $this->nativePrograms()[0];
        $units = $program['commands'][0]['value']['scene_units'];
        $this->assertSame($units[0], $units[1]);
        $this->assertSame([10, 10], array_column($units, 'v'));
        $this->assertSame([0, 0], array_column($units, 'bright'));
        $frames = $this->frames();
        $this->assertSame(['h' => 0, 's' => 0, 'v' => 0, 'bright' => 10, 'temperature' => 0], $frames[0]['channels']);
        $this->assertGreaterThanOrEqual(5000, $frames[0]['at'] - $program['at']);
        $this->assertGreaterThan(20, count($frames));
        $this->assertLessThan(100, $frames[5]['channels']['bright']);
        foreach ($frames as $frame) {
            $this->assertSame(0, $frame['channels']['v']);
            $this->assertGreaterThanOrEqual(10, $frame['channels']['bright']);
        }
        $this->assertSame([10, 1000], array_values(array_filter(array_column($this->commits(), 'brightness'), fn ($v) => $v !== null)));
    }

    public function test_onboard_retarget_waits_original_deadline_and_never_commits_superseded_scene(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->fixture->white('dark');
        $this->send($this->mythos('blue'));
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'onboard_wait');
        $until = $state->native_effect['onboard']['until'];
        $this->send($this->mythos('green'));
        $this->tick();
        $this->send($this->white());
        $state = $this->tick();
        $this->assertSame($until, $state->native_effect['onboard']['until']);
        $this->assertSame('onboard_wait', $state->native_effect['phase']);
        $this->assertSame(10, $state->native_effect['output']['channels']['v']);
        $this->assertCount(2, $this->fixture->writes); // Anchor and native program only.
        $this->finish();
        foreach ($this->commits() as $commit) {
            if ($commit['commands'][0]['code'] === 'scene_data_v2') {
                $units = $commit['commands'][0]['value']['scene_units'];
                $this->assertCount(2, $units, 'No superseded captured scene can be committed');
                $this->assertSame($units[0], $units[1]);
                $this->assertSame(199, $units[0]['h']);
            }
        }
        $this->assertSame(1000, $this->fixture->values[22]);
    }

    public function test_onboard_restart_and_new_owner_keep_original_wait_without_reposting(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'onboard_wait');
        $until = $state->native_effect['onboard']['until'];
        app(LightingControl::class)->revokeSession($this->user, 'continuous-fixture');
        $this->tick(1000);
        $this->epoch = app(LightingControl::class)->control($this->user, 'continuous-fixture', true, null)['controlEpoch'];
        $this->send($this->white('encounters'));
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->tick(1000);
        $this->assertSame($until, $state->native_effect['onboard']['until']);
        $this->assertSame('onboard_wait', $state->native_effect['phase']);
        $this->assertCount(1, $this->fixture->writes);
        $this->finish();
        $this->assertCount(1, $this->nativePrograms());
        $this->assertSame([10, 901], array_values(array_filter(array_column($this->commits(), 'brightness'), fn ($v) => $v !== null)));
    }

    public function test_onboard_unknown_post_is_reconciled_by_exact_fresh_program_without_resend(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->fixture->throwAt = 1;
        $this->fixture->applyBeforeThrow = true;
        $this->fixture->beforeWrite = function () {
            $pending = LightingState::findOrFail(1)->native_effect['pending'];
            $this->assertNull($pending['receipt']);
            $this->assertSame('native_fade', $pending['descriptor']['operation']);
            $this->assertNotEmpty($pending['descriptor']['expected'][25]);
        };
        $this->send($this->mythos());
        $state = $this->until(fn () => count($this->fixture->writes) === 1);
        $this->fixture->beforeWrite = null;
        $this->assertSame('transport_timeout', $state->error);
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->tick();
        $this->assertNull($state->error);
        $this->assertSame('onboard_wait', $state->native_effect['phase']);
        $this->assertSame(1000, $state->native_effect['output']['channels']['bright']);
        $this->finish();
        $this->assertCount(1, $this->nativePrograms());
    }

    public function test_onboard_stale_readback_or_external_change_never_permits_an_endpoint(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->fixture->staleTimes = true;
        $this->send($this->mythos());
        $this->until(fn () => count($this->fixture->writes) === 1);
        $state = $this->tick(16000);
        // The report shows an uncaptured onboard program: it is never adopted as an origin.
        $this->assertSame('unsupported_transition', $state->error);
        $this->assertNotNull($state->native_effect['pending']);
        $this->send($this->white());
        $this->tick();
        $this->assertCount(1, $this->fixture->writes);
    }

    public function test_onboard_unknown_unreported_post_expires_and_newest_intent_continues_without_resend(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->fixture->throwAt = 1;
        $this->send($this->mythos());
        $state = $this->until(fn () => count($this->fixture->writes) === 1);
        $attempt = $state->native_effect['pending']['id'];
        $this->send($this->white());
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->tick(5000);
        $this->assertSame($attempt, $state->native_effect['pending']['id'], 'Held until its deadline');
        $this->assertCount(1, $this->fixture->writes);

        $state = $this->tick(11000);
        $this->assertNull($state->error);
        $this->assertNull($state->native_effect['pending']);
        $this->assertSame($attempt, $state->native_effect['unconfirmedEndpoint']['id']);
        $state = $this->finish();
        $this->assertSame($this->white(), $state->native_effect['target']);
        $this->assertCount(1, $this->fixture->writes, 'The lost program is never resent');
    }

    public function test_onboard_revocation_during_post_permits_only_readback_reconciliation(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->fixture->afterWrite = fn () => app(LightingControl::class)->revokeSession($this->user, 'continuous-fixture');
        $this->send($this->mythos());
        $this->until(fn () => count($this->fixture->writes) === 1);
        $state = $this->tick();
        $this->assertFalse($state->enabled);
        $this->assertNull($state->native_effect['pending']);
        $this->assertSame('onboard_wait', $state->native_effect['phase']);
        $this->tick(6000);
        $this->assertCount(1, $this->fixture->writes);
        $this->assertSame([], $this->frames());
    }

    public function test_onboard_external_state_after_qualified_program_blocks_followup_and_release_sends_nothing(): void
    {
        config()->set('lighting.cloud.onboard_fades', true);
        $this->send($this->mythos());
        $this->until(fn ($state) => $state->native_effect['phase'] === 'onboard_wait');
        $this->fixture->values[25] = 'external-scene';
        $state = $this->tick(6000);
        $this->assertSame('unsupported_transition', $state->error);
        app(LightingControl::class)->revokeSession($this->user, 'continuous-fixture');
        $this->tick(6000);
        $this->assertCount(1, $this->fixture->writes);
    }

    public function test_white_to_mythos_stays_on_at_calibrated_dark_without_claiming_physical_confirmation(): void
    {
        $this->send($this->mythos());
        $state = $this->finish();
        $frames = $this->frames();
        $brightness = array_column(array_column($frames, 'channels'), 'bright');
        $this->assertGreaterThan(5, count($frames));
        $this->assertSame(10, end($brightness));
        $sorted = $brightness;
        rsort($sorted);
        $this->assertSame($sorted, $brightness);
        $this->assertSame([10], array_column($this->commits(), 'brightness'));
        $this->assertTrue($this->fixture->values[20]);
        $this->assertSame('dark', $state->stage);
        $this->assertFalse($state->observed['physicalConfirmed']);
        $this->assertSame('cloud_readback', $state->observed['completion']);
        $this->assertLessThan(count($frames), $this->fixture->reads, 'Reads are boundary-only, not one per frame');
        $before = count($this->fixture->writes);
        $this->tick(10000);
        $this->assertCount($before, $this->fixture->writes);
    }

    public function test_dark_to_scene_crossfades_before_exact_captured_program_commit(): void
    {
        $this->fixture->white('dark');
        $this->send($this->mythos('blue'));
        $state = $this->finish();
        $frames = $this->frames();
        $this->assertGreaterThan(5, count($frames));
        $this->assertSame(['h' => 199, 's' => 1000, 'v' => 10, 'bright' => 0, 'temperature' => 0], $frames[0]['channels']);
        $this->assertGreaterThanOrEqual(400, $frames[1]['at'] - $frames[0]['at']);
        foreach ($frames as $frame) {
            $this->assertSame(0, $frame['channels']['bright']);
            $this->assertGreaterThanOrEqual(10, $frame['channels']['v']);
        }
        $this->assertSame(['h' => 199, 's' => 1000, 'v' => 1000, 'bright' => 0, 'temperature' => 0], end($frames)['channels']);
        $this->assertSame([['code' => 'scene_data_v2', 'value' => $this->fixture->scenes()['blue']['value']],
            ['code' => 'work_mode', 'value' => 'scene']], $this->commits()[0]['commands']);
        $this->assertSame('scene', $state->stage);
        $this->assertSame('blue', $state->observed['color']);
        $this->assertTrue($state->observed['scenePhaseApproximate']);
        $this->assertFalse($state->observed['physicalConfirmed']);
        $this->assertGreaterThanOrEqual(4000, $this->commits()[0]['at'] - $frames[0]['at']);
    }

    public function test_known_scene_to_white_fades_rgb_then_commits_dark_before_twelve_second_rise_without_off(): void
    {
        $this->fixture->scene('blue');
        $this->send($this->white());
        $state = $this->finish();
        $commits = $this->commits();
        $this->assertSame([10, 1000], array_column($commits, 'brightness'));
        $first = $this->frames()[0]['channels'];
        $this->assertSame(199, $first['h']);
        $this->assertSame(0, $first['bright']);
        $this->assertLessThan(1000, $first['v']);
        $this->assertGreaterThanOrEqual(12000, $commits[1]['at'] - $commits[0]['at']);
        $this->assertTrue($this->fixture->values[20]);
        $this->assertSame([1000, 332], [$this->fixture->values[22], $this->fixture->values[23]]);
        $this->assertSame('white', $state->stage);
        $frames = $this->frames();
        $bridgeIndex = array_find_key($frames, fn ($frame) => $frame['channels']['v'] === 0);
        $this->assertNotNull($bridgeIndex);
        $this->assertSame(10, $frames[$bridgeIndex - 1]['channels']['v']);
        $this->assertSame(0, $frames[$bridgeIndex - 1]['channels']['bright']);
        $this->assertSame(['h' => 0, 's' => 0, 'v' => 0, 'bright' => 10, 'temperature' => 0], $frames[$bridgeIndex]['channels']);
    }

    public function test_each_captured_scene_is_stopped_once_before_monotonic_rgb_dimming(): void
    {
        foreach (['blue', 'green', 'yellow'] as $color) {
            $this->fixture->scene($color);
            $rawScene = $this->fixture->values[25];
            $before = count($this->fixture->writes);
            $this->fixture->beforeWrite = function () {
                $pending = LightingState::findOrFail(1)->native_effect['pending'];
                $this->assertNotNull($pending);
                $this->assertNull($pending['receipt']);
                if ($pending['descriptor']['operation'] === 'static_colour') {
                    $this->assertSame('colour', $pending['descriptor']['expected'][21]);
                    $this->assertSame([21, 24], $pending['descriptor']['freshFields']);
                } elseif ($pending['descriptor']['operation'] === 'realtime' && $pending['descriptor']['channels']['v'] > 0) {
                    $this->assertSame('colour', $this->fixture->values[21], 'No RGB fade while the scene engine remains active');
                }
            };
            $this->send($this->white());
            $this->finish();
            $writes = array_slice($this->fixture->writes, $before);
            $this->assertSame('static_colour', $writes[0]['operation']);
            $this->assertCount(1, array_filter($writes, fn ($write) => $write['operation'] === 'static_colour'));
            $this->assertSame(1000, $writes[0]['channels']['v']);
            $minimumReached = false;
            $lastValue = 1000;
            foreach ($writes as $write) {
                if ($write['operation'] !== 'realtime') {
                    continue;
                }
                $channels = $write['channels'];
                if ($channels['v'] === 0) {
                    $minimumReached = true;
                } else {
                    $this->assertFalse($minimumReached, 'RGB cannot rise again after the white bridge');
                    $this->assertLessThanOrEqual($lastValue, $channels['v']);
                    $this->assertGreaterThanOrEqual(10, $channels['v']);
                    $lastValue = $channels['v'];
                }
            }
            $this->assertTrue($minimumReached);
            $this->assertSame(10, $lastValue);
            $this->assertSame($rawScene, $this->fixture->values[25], 'The captured program remains stored but inactive');
            $this->assertSame('white', $this->fixture->values[21]);
            $this->fixture->beforeWrite = null;
        }
    }

    public function test_unknown_static_colour_write_is_resolved_by_fresh_readback_without_resending_after_restart(): void
    {
        $this->fixture->scene('blue');
        $this->fixture->throwAt = 1;
        $this->fixture->applyBeforeThrow = true;
        $this->send($this->white());
        $state = $this->until(fn () => count($this->colourHolds()) === 1);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNull($state->native_effect['pending']['receipt']);
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $state = $this->tick();
        $this->assertNull($state->error);
        $this->assertNull($state->native_effect['pending']);
        $this->assertSame('colour', $state->native_effect['output']['kind']);
        $this->assertTrue($state->native_effect['output']['approximate']);
        $this->finish();
        $this->assertCount(1, $this->colourHolds());
    }

    public function test_retarget_during_static_colour_write_never_reactivates_superseded_scene(): void
    {
        $this->fixture->scene('blue');
        $this->fixture->afterWrite = function () {
            $this->fixture->afterWrite = null;
            $this->send($this->mythos('green'));
        };
        $this->send($this->white());
        $this->until(fn () => count($this->colourHolds()) === 1);
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $this->finish();
        $this->assertCount(1, $this->colourHolds());
        $sceneCommits = array_values(array_filter($this->commits(), fn ($write) => $write['commands'][0]['code'] === 'scene_data_v2'));
        $this->assertCount(1, $sceneCommits);
        $this->assertSame($this->fixture->scenes()['green']['value'], $sceneCommits[0]['commands'][0]['value']);
    }

    public function test_unknown_static_colour_post_with_stale_readback_is_adopted_after_deadline_without_repost(): void
    {
        $this->fixture->scene('blue');
        $this->fixture->staleTimes = true;
        $this->fixture->throwAt = 1;
        $this->fixture->applyBeforeThrow = true;
        $this->send($this->white());
        $this->until(fn () => count($this->colourHolds()) === 1);
        $state = $this->tick(5000);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNotNull($state->native_effect['pending'], 'Stale times never confirm before the deadline');

        $state = $this->tick(11000);
        $this->assertNull($state->error);
        $this->assertNull($state->native_effect['pending']);
        $this->assertSame('colour', $state->native_effect['output']['kind']);
        $this->fixture->staleTimes = false;
        $state = $this->finish();
        $this->assertSame($this->white(), $state->native_effect['target']);
        $this->assertCount(1, $this->colourHolds(), 'The hold already in the report is never reposted');
    }

    public function test_owned_static_colour_can_resume_as_source_without_a_second_hold(): void
    {
        $this->fixture->values[21] = 'colour';
        $this->fixture->values[24] = '00c703e801d0'; // Hue 199, saturation 1000, value 464.
        $this->send($this->white());
        $state = $this->tick();
        $this->assertSame('colour', $state->native_effect['output']['kind']);
        $this->assertSame(464, $state->native_effect['output']['channels']['v']);
        $this->assertFalse($state->native_effect['output']['approximate']);
        $this->finish();
        $this->assertSame([], $this->colourHolds());
        foreach ($this->frames() as $frame) {
            $this->assertLessThanOrEqual(464, $frame['channels']['v']);
        }
    }

    public function test_white_to_white_uses_exact_encounters_calibration_without_dark_detour(): void
    {
        $this->send($this->white('encounters'));
        $this->finish();
        $this->assertSame([901], array_column($this->commits(), 'brightness'));
        $this->assertSame(197, $this->fixture->values[23]);
        foreach ($this->frames() as $frame) {
            $this->assertGreaterThanOrEqual(901, $frame['channels']['bright']);
            $this->assertSame(0, $frame['channels']['v']);
        }
    }

    public function test_retarget_uses_last_accepted_realtime_channels_instead_of_stale_stored_full_brightness(): void
    {
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['output']['kind'] === 'realtime'
            && $state->native_effect['output']['channels']['bright'] < 600 && $state->native_effect['pending'] === null);
        $origin = $state->native_effect['output']['channels'];
        $this->assertSame(1000, $this->fixture->values[22], 'DP28 does not change stored brightness');
        $this->send($this->white('encounters'));
        $this->tick(); // boundary and adopt
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertSame($origin, $state->native_effect['fade']['from']);
        $this->assertSame(901, $state->native_effect['fade']['to']['bright']);
        $this->finish();
        $this->assertSame([901], array_column($this->commits(), 'brightness'));
    }

    public function test_retarget_during_scene_entry_never_commits_old_scene_or_resets_rgb_to_full(): void
    {
        $this->fixture->white('dark');
        $this->send($this->mythos('blue'));
        $state = $this->until(fn ($state) => $state->native_effect['output']['kind'] === 'realtime'
            && $state->native_effect['output']['channels']['v'] > 200 && $state->native_effect['pending'] === null);
        $origin = $state->native_effect['output']['channels'];
        $this->send($this->white());
        $this->tick();
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertSame($origin, $state->native_effect['fade']['from']);
        $this->finish();
        foreach ($this->commits() as $commit) {
            $this->assertNotContains('scene_data_v2', array_column($commit['commands'], 'code'));
        }
    }

    public function test_retarget_after_minimum_color_anchor_settles_then_returns_to_dark_without_old_scene(): void
    {
        $this->fixture->white('dark');
        $this->send($this->mythos('blue'));
        $this->until(fn ($state) => $state->native_effect['phase'] === 'settle');
        $this->assertCount(1, $this->frames());
        $anchorAt = $this->frames()[0]['at'];
        $this->send($this->white());
        $this->finish();
        $frames = $this->frames();
        $this->assertSame(['h' => 0, 's' => 0, 'v' => 0, 'bright' => 10, 'temperature' => 0], $frames[1]['channels']);
        $this->assertGreaterThanOrEqual(400, $frames[1]['at'] - $anchorAt);
        foreach ($this->commits() as $commit) {
            $this->assertNotContains('scene_data_v2', array_column($commit['commands'], 'code'));
        }
    }

    public function test_restart_after_color_anchor_keeps_minimum_channels_and_does_not_invent_full_rgb(): void
    {
        $this->fixture->white('dark');
        $this->send($this->mythos('blue'));
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'settle');
        $this->assertSame(10, $state->native_effect['output']['channels']['v']);
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $this->send($this->white());
        $this->finish();
        $this->assertSame(10, $this->frames()[1]['channels']['bright']);
        $this->assertSame(0, $this->frames()[1]['channels']['v']);
        $this->assertSame([10, 1000], array_column($this->commits(), 'brightness'));
    }

    public function test_every_sent_luminous_channel_respects_its_minimum_and_never_forms_a_dark_pair(): void
    {
        $this->send($this->mythos());
        $this->finish();
        $this->send($this->mythos('blue'));
        $this->finish();
        $this->send($this->white());
        $this->finish();
        $this->send($this->white('encounters'));
        $this->finish();
        foreach ($this->frames() as $frame) {
            $channels = $frame['channels'];
            foreach (['v', 'bright'] as $field) {
                $this->assertTrue($channels[$field] === 0 || $channels[$field] >= 10, json_encode($channels));
            }
            $this->assertGreaterThanOrEqual(10, $channels['v'] + $channels['bright']);
        }
    }

    public function test_unknown_frame_holds_until_its_deadline_then_newest_intent_resumes_after_restart(): void
    {
        $this->fixture->throwAt = 1;
        $this->fixture->beforeWrite = function () {
            $pending = LightingState::findOrFail(1)->native_effect['pending'];
            $this->assertNotNull($pending);
            $this->assertNull($pending['receipt']);
        };
        $this->send($this->mythos());
        $this->until(fn () => count($this->fixture->writes) === 1);
        $this->fixture->beforeWrite = null;
        $state = LightingState::findOrFail(1);
        $attempt = $state->native_effect['pending']['id'];
        $this->assertSame('transport_timeout', $state->error);
        $this->send($this->white('encounters'));
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        for ($i = 0; $i < 2; $i++) {
            $state = $this->tick(5000);
        }
        $this->assertCount(1, $this->fixture->writes);
        $this->assertSame($attempt, $state->native_effect['pending']['id']);
        $this->assertSame('transport_timeout', $state->error);

        // Production incident 2026-10-11: this frame previously blocked every later gesture forever.
        $state = $this->tick(6000);
        $this->assertNull($state->error);
        $this->assertNull($state->native_effect['pending']);
        $this->assertDatabaseHas('lighting_events', ['operation' => 'continuous_frame_unconfirmed']);
        $state = $this->finish();
        $this->assertSame($this->white('encounters'), $state->native_effect['target']);
        $this->assertSame(901, $this->fixture->values[22]);
    }

    public function test_lost_frame_mid_fade_resumes_the_same_gesture_without_a_new_click(): void
    {
        $this->send($this->mythos());
        $this->until(fn () => count($this->frames()) === 3);
        $this->fixture->throwAt = count($this->fixture->writes) + 1;
        $state = $this->until(fn ($state) => $state->error === 'transport_timeout');
        $writes = count($this->fixture->writes);
        $lost = end($this->fixture->writes)['channels'];
        $origin = $state->native_effect['output']['channels'];
        $this->assertSame('transport_uncertain', $state->observed['completion']);

        $state = $this->tick(5000);
        $this->assertCount($writes, $this->fixture->writes, 'The lost frame is never resent');
        $state = $this->tick(11000);
        $this->assertNull($state->error);
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertSame($origin, $state->native_effect['fade']['from'], 'Resume from the last acknowledged frame');
        $this->assertNotSame($lost, $origin);
        $state = $this->finish();
        $this->assertSame('dark', $state->stage);
        $this->assertSame(10, $this->fixture->values[22]);
    }

    public function test_read_timeout_before_an_endpoint_resumes_by_itself_after_a_short_pause(): void
    {
        $this->send($this->mythos());
        $this->until(fn ($state) => $state->native_effect['phase'] === 'settle');
        $this->fixture->beforeRead = function () {
            $this->fixture->beforeRead = null;
            throw new TuyaCloudException('transport_timeout');
        };
        $state = $this->tick(500);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNotSame('transport_uncertain', $state->observed['completion'] ?? null, 'A failed read is not a lost write');
        $writes = count($this->fixture->writes);
        $state = $this->tick(1000);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertCount($writes, $this->fixture->writes);

        $state = $this->tick(1100);
        $this->assertNull($state->error);
        $state = $this->finish();
        $this->assertSame('dark', $state->stage);
        $this->assertSame(10, $this->fixture->values[22]);
    }

    public function test_read_timeout_before_the_first_write_never_poisons_later_gestures(): void
    {
        $this->fixture->beforeRead = function () {
            $this->fixture->beforeRead = null;
            throw new TuyaCloudException('transport_timeout');
        };
        $this->send($this->white('encounters'));
        $state = $this->tick();
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNull($state->native_effect);
        $this->assertSame([], $this->fixture->writes);

        $state = $this->tick(2100);
        $this->assertNull($state->error);
        $state = $this->finish();
        $this->assertSame($this->white('encounters'), $state->native_effect['target']);
        $this->assertSame(901, $this->fixture->values[22]);
    }

    public function test_restart_with_known_receipt_rebases_from_last_frame_without_catching_up_to_endpoint(): void
    {
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['output']['kind'] === 'realtime'
            && $state->native_effect['pending'] === null);
        $origin = $state->native_effect['output']['channels'];
        $this->coordinator = $this->makeCoordinator();
        $this->coordinator->recover();
        $this->tick(20000);
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertSame($origin, $state->native_effect['fade']['from']);
        $this->assertSame($this->now, $state->native_effect['fade']['startedAt']);
        $this->finish();
    }

    public function test_revoke_during_post_saves_receipt_but_never_follows_with_commit_or_frame(): void
    {
        $this->fixture->afterWrite = fn () => app(LightingControl::class)->revokeSession($this->user, 'continuous-fixture');
        $this->send($this->mythos());
        $state = $this->until(fn () => count($this->fixture->writes) === 1);
        $this->assertNotNull($state->native_effect['pending']['receipt']);
        $this->assertFalse($state->enabled);
        for ($i = 0; $i < 8; $i++) {
            $state = $this->tick(1000);
        }
        $this->assertCount(1, $this->fixture->writes);
        $this->assertNull($state->native_effect['pending']);
    }

    public function test_new_owner_requesting_the_same_target_rebases_after_a_released_pause(): void
    {
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['output']['kind'] === 'realtime'
            && $state->native_effect['pending'] === null);
        $origin = $state->native_effect['output']['channels'];
        app(LightingControl::class)->revokeSession($this->user, 'continuous-fixture');
        $this->tick(20000);
        $this->epoch = app(LightingControl::class)->control($this->user, 'continuous-fixture', true, null)['controlEpoch'];
        $this->send($this->mythos());
        $this->tick();
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertSame($origin, $state->native_effect['fade']['from']);
        $this->assertSame($this->now, $state->native_effect['fade']['startedAt']);
        $this->assertGreaterThan(0, $state->native_effect['fade']['duration']);
        $this->finish();
    }

    public function test_boundary_change_blocks_endpoint_commit_after_realtime_frames(): void
    {
        $this->send($this->mythos());
        $this->until(fn ($state) => $state->native_effect['phase'] === 'settle');
        $this->fixture->values[20] = false;
        $state = $this->tick(500);
        $this->assertSame('unsupported_transition', $state->error);
        $this->assertSame([], $this->commits());
        $this->assertNull($state->dark_since_ms);
    }

    public function test_new_gesture_rebases_completed_legacy_plan_from_changed_white_without_replaying_old_target(): void
    {
        $this->send($this->white());
        $state = $this->finish();
        $oldId = $state->native_effect['id'];
        $effect = $state->native_effect;
        $effect['phase'] = 'plan'; // Older recover() already moved the completed effect here.
        $effect['fade'] = ['purpose' => 'white', 'from' => ['bright' => 10]];
        $state->native_effect = $effect;
        $state->error = 'unsupported_transition';
        $state->observed = array_replace($state->observed, ['completion' => 'transport_uncertain']);
        $state->save();
        $this->fixture->values[22] = 464;
        $this->coordinator->recover();
        $reads = $this->fixture->reads;
        $this->tick(20000);
        $this->assertSame($reads, $this->fixture->reads, 'Restart cannot clear an error or replay a target');
        $this->assertSame([], $this->fixture->writes);

        $this->epoch = app(LightingControl::class)->control($this->user, 'continuous-fixture', true, null)['controlEpoch'];
        $this->send($this->mythos());
        $state = $this->tick();
        $this->assertNull($state->error);
        $this->assertNotSame($oldId, $state->native_effect['id']);
        $this->assertSame(464, $state->native_effect['output']['channels']['bright']);
        $this->assertArrayNotHasKey('fade', $state->native_effect);
        $this->assertSame($state->revision, $state->native_effect['revision']);
        $this->assertSame($state->control_generation, $state->native_effect['generation']);
        $this->assertSame('reported', $state->observed['quality']);
        $this->assertFalse($state->observed['physicalConfirmed']);
        $this->assertFalse($state->observed['outputSettled']);
        $this->assertSame([], $this->fixture->writes, 'Adoption itself is read-only');
        $this->finish();
        foreach ($this->frames() as $frame) {
            $this->assertLessThanOrEqual(464, $frame['channels']['bright']);
        }
        $this->assertSame([10], array_column($this->commits(), 'brightness'));
    }

    public function test_restart_leaves_completed_target_idle_even_if_reported_brightness_changed(): void
    {
        $this->send($this->white());
        $state = $this->finish();
        $this->fixture->values[22] = 464;
        $reads = $this->fixture->reads;
        $this->coordinator->recover();
        for ($i = 0; $i < 3; $i++) {
            $state = $this->tick(2000);
        }
        $this->assertSame('complete', $state->native_effect['phase']);
        $this->assertNull($state->error);
        $this->assertSame($reads, $this->fixture->reads);
        $this->assertSame([], $this->fixture->writes);
    }

    public function test_completed_effect_rebases_only_exact_known_on_scene_and_keeps_phase_approximate(): void
    {
        $this->send($this->white());
        $this->finish();
        $this->fixture->scene('blue');
        $this->send($this->mythos());
        $state = $this->tick();
        $this->assertNull($state->error);
        $this->assertSame('scene', $state->native_effect['output']['kind']);
        $this->assertSame('blue', $state->native_effect['output']['color']);
        $this->assertTrue($state->native_effect['output']['approximate']);
        $this->assertTrue($state->observed['scenePhaseApproximate']);
        $this->assertFalse($state->observed['physicalConfirmed']);
        $this->assertSame([], $this->fixture->writes);
    }

    public function test_completed_effect_cannot_rebase_from_off_or_unknown_scene(): void
    {
        $this->send($this->white());
        $state = $this->finish();
        $id = $state->native_effect['id'];
        foreach ([[20 => false], [20 => true, 21 => 'scene', 25 => 'unknown-scene']] as $change) {
            $this->fixture->values = array_replace($this->fixture->values, $change);
            $this->send($this->mythos());
            $state = $this->tick();
            $this->assertSame('unsupported_transition', $state->error);
            $this->assertSame($id, $state->native_effect['id']);
            $this->assertSame([], $this->fixture->writes);
        }
    }

    public function test_new_intent_adopts_externally_changed_white_instead_of_blocking(): void
    {
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['output']['kind'] === 'realtime'
            && $state->native_effect['pending'] === null);
        $effect = $state->native_effect;
        $writes = count($this->fixture->writes);
        $this->fixture->values[22] = 464;
        $this->send($this->white());
        $state = $this->tick();
        $this->assertNull($state->error);
        $this->assertNotSame($effect['id'], $state->native_effect['id']);
        $this->assertSame(['kind' => 'white', 'channels' => ['h' => 0, 's' => 0, 'v' => 0, 'bright' => 464, 'temperature' => 332],
            'approximate' => false], $state->native_effect['output']);
        $this->assertCount($writes, $this->fixture->writes);
        $this->assertDatabaseHas('lighting_events', ['operation' => 'continuous_external_state_adopted']);
        $this->finish();
        $this->assertSame(1000, $this->fixture->values[22]);
    }

    public function test_lamp_switched_off_mid_fade_recovers_on_the_next_gesture_once_it_is_on(): void
    {
        $this->send($this->mythos());
        $this->until(fn ($state) => $state->native_effect['phase'] === 'settle');
        $this->fixture->values[20] = false;
        $state = $this->tick(500);
        $this->assertSame('unsupported_transition', $state->error);
        $this->assertSame([], $this->commits());

        $this->fixture->values = array_replace($this->fixture->values, [20 => true, 21 => 'white', 22 => 500, 23 => 0]);
        $this->send($this->white());
        $state = $this->finish();
        $this->assertNull($state->error);
        $this->assertSame(1000, $this->fixture->values[22]);
    }

    public function test_rebase_read_cannot_overwrite_a_newer_intent_or_released_ownership(): void
    {
        $this->send($this->white());
        $state = $this->finish();
        $id = $state->native_effect['id'];
        $this->fixture->values[22] = 464;
        $this->send($this->mythos());
        $this->fixture->beforeRead = function () {
            $this->fixture->beforeRead = null;
            $this->send($this->white('encounters'));
        };
        $state = $this->tick();
        $this->assertSame($id, $state->native_effect['id']);
        $this->assertSame($this->white('encounters'), $state->desired_target);
        $this->assertNull($state->error);

        $this->fixture->beforeRead = function () {
            $this->fixture->beforeRead = null;
            app(LightingControl::class)->revokeSession($this->user, 'continuous-fixture');
        };
        $state = $this->tick();
        $this->assertFalse($state->enabled);
        $this->assertSame($id, $state->native_effect['id']);
        $this->assertSame([], $this->fixture->writes);
    }

    public function test_unknown_endpoint_requires_fresh_times_until_deadline_then_adopts_report_without_repost(): void
    {
        $this->fixture->staleTimes = true;
        $this->fixture->applyBeforeThrow = true;
        $this->fixture->beforeWrite = function () {
            if ((LightingState::findOrFail(1)->native_effect['pending']['descriptor']['operation'] ?? null) === 'white') {
                $this->fixture->throwAt = count($this->fixture->writes) + 1;
            }
        };
        $this->send($this->mythos());
        $this->until(fn () => count($this->commits()) === 1);
        $state = $this->tick(5000);
        $this->assertSame('transport_timeout', $state->error);
        $this->assertNotNull($state->native_effect['pending']);
        $this->assertNotSame($state->revision, $state->applied_revision);

        $state = $this->tick(11000);
        $this->assertNull($state->error);
        $this->assertNull($state->native_effect['pending']);
        $state = $this->finish();
        $this->assertSame('dark', $state->stage);
        $this->assertCount(1, $this->commits(), 'The endpoint already in the report is never reposted');
    }

    public function test_frames_are_paced_skip_backlog_and_do_not_read_before_each_post(): void
    {
        $this->send($this->mythos());
        $this->until(fn () => count($this->frames()) === 2);
        $reads = $this->fixture->reads;
        $this->tick(); // reconcile second frame
        $before = count($this->frames());
        $started = microtime(true);
        $this->tick(1600);
        $this->assertLessThan(0.2, microtime(true) - $started);
        $this->assertCount($before + 1, $this->frames());
        $this->assertSame($reads, $this->fixture->reads);
        $this->finish();
        $previous = null;
        foreach ($this->frames() as $frame) {
            if ($previous !== null) {
                $this->assertGreaterThanOrEqual(300, $frame['at'] - $previous);
            }
            $previous = $frame['at'];
        }
    }

    public function test_rising_white_uses_the_same_perceptual_curve_as_the_bounded_probe(): void
    {
        $this->fixture->white('dark');
        $this->send($this->white());
        $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->tick(3000); // 25% of a 12 s rise: p^2.2 is about 4.7% output.
        $channels = $this->frames()[0]['channels'];
        $this->assertSame((int) round(10 + 990 * (0.25 ** 2.2)), $channels['bright']);
        $this->assertSame((int) round(332 * (0.25 ** 2.2)), $channels['temperature']);
        $this->assertLessThan(100, $channels['bright']);
        $this->assertSame(0, $channels['v']);
    }

    public function test_white_dark_fade_is_the_temporal_inverse_of_the_accepted_rise(): void
    {
        config()->set('lighting.white_fade_down_ms', 12000);
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $started = $state->native_effect['fade']['startedAt'];
        $this->assertSame(12000, $state->native_effect['fade']['duration']);
        foreach ([[3000, 536, 176], [6000, 225, 72], [9000, 57, 16], [12000, 10, 0]] as [$offset, $brightness, $temperature]) {
            $this->tick($started + $offset - $this->now);
            $frames = $this->frames();
            $last = end($frames);
            $this->assertSame($started + $offset, $last['at']);
            $this->assertSame(['h' => 0, 's' => 0, 'v' => 0, 'bright' => $brightness, 'temperature' => $temperature], $last['channels']);
            $this->tick(0); // Reconcile at the same clock value, without extra frames.
        }
        $this->assertCount(4, $this->frames());
        $this->finish();
        $this->assertSame([10], array_column($this->commits(), 'brightness'));
    }

    public function test_white_to_white_dimming_uses_the_inverse_curve_without_a_dark_detour(): void
    {
        config()->set('lighting.white_fade_down_ms', 12000);
        $this->send($this->white('encounters'));
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertSame(1620, $state->native_effect['fade']['duration']);
        $this->tick(405); // 25% through this shorter calibrated span.
        $this->assertSame(['h' => 0, 's' => 0, 'v' => 0, 'bright' => 954, 'temperature' => 269], $this->frames()[0]['channels']);
        $this->finish();
        $this->assertSame([901], array_column($this->commits(), 'brightness'));
    }

    public function test_scene_rise_uses_perceptual_curve_from_minimum_to_full_rgb(): void
    {
        config()->set('lighting.scene_fade_in_ms', 12000);
        $this->fixture->white('dark');
        $this->send($this->mythos('blue'));
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade'
            && $state->native_effect['fade']['purpose'] === 'scene');
        $started = $state->native_effect['fade']['startedAt'];
        $this->assertCount(1, $this->frames()); // The minimum RGB anchor remains one command.
        foreach ([[3000, 57], [6000, 225], [9000, 536], [12000, 1000]] as [$offset, $value]) {
            $this->tick($started + $offset - $this->now);
            $frames = $this->frames();
            $this->assertSame(['h' => 199, 's' => 1000, 'v' => $value, 'bright' => 0, 'temperature' => 0], end($frames)['channels']);
            $this->tick(0);
        }
        $this->finish();
        $this->assertCount(5, $this->frames());
        $this->assertCount(1, $this->commits());
        foreach ($this->frames() as $frame) {
            $this->assertGreaterThanOrEqual(10, $frame['channels']['v']);
        }
    }

    public function test_rgb_dimming_uses_the_time_reversed_curve_and_preserves_its_hue(): void
    {
        config()->set('lighting.scene_fade_out_ms', 12000);
        $this->fixture->scene('blue');
        $this->send($this->mythos());
        $state = $this->until(fn ($state) => $state->native_effect['phase'] === 'fade');
        $this->assertSame('rgb_down', $state->native_effect['fade']['purpose']);
        $started = $state->native_effect['fade']['startedAt'];
        foreach ([[3000, 536], [6000, 225], [9000, 57], [12000, 10]] as [$offset, $value]) {
            $this->tick($started + $offset - $this->now);
            $frames = $this->frames();
            $this->assertSame(['h' => 199, 's' => 1000, 'v' => $value, 'bright' => 0, 'temperature' => 0], end($frames)['channels']);
            $this->tick(0);
        }
        $this->finish();
        $this->assertCount(5, $this->frames()); // Four fade samples and the single white minimum bridge.
        $this->assertSame([10], array_column($this->commits(), 'brightness'));
    }

    public function test_slow_ack_does_not_add_an_extra_frame_interval_after_network_latency(): void
    {
        $this->send($this->mythos());
        $this->until(fn () => count($this->frames()) === 1);
        $sentAt = $this->frames()[0]['at'];
        $state = $this->tick(700); // Delayed receipt reconciliation.
        $this->assertSame($this->now, $state->native_effect['notBefore']);
        $this->tick(100);
        $this->assertCount(2, $this->frames());
        $this->assertSame(800, $this->frames()[1]['at'] - $sentAt);
    }

    public function test_disabled_experiment_gate_is_readonly_and_unknown_scene_never_starts(): void
    {
        config()->set('lighting.cloud.continuous_transitions', false);
        $this->send($this->mythos());
        $state = $this->tick();
        $this->assertSame($this->now, $state->worker_seen_ms);
        $this->assertSame(0, $this->fixture->reads);
        $this->assertSame([], $this->fixture->writes);
        config()->set('lighting.cloud.continuous_transitions', true);
        $this->fixture->values[21] = 'scene';
        $this->fixture->values[25] = 'external-unknown';
        $state = $this->tick();
        $this->assertSame('unsupported_transition', $state->error);
        $this->assertSame([], $this->fixture->writes);
    }
}

class ContinuousCloudFixture extends TuyaCloudClient
{
    public array $values = [20 => true, 21 => 'white', 22 => 1000, 23 => 332, 24 => '000003e803e8', 25 => 'fixture-blue', 35 => ['onMs' => 800, 'offMs' => 4000]];

    public array $times;

    public array $writes = [];

    public int $reads = 0;

    public ?int $throwAt = null;

    public bool $staleTimes = false;

    public bool $applyBeforeThrow = false;

    public array $discardedWrites = [];

    public ?Closure $beforeWrite = null;

    public ?Closure $afterWrite = null;

    public ?Closure $beforeRead = null;

    public function __construct(public Closure $clock)
    {
        $this->values[25] = $this->scenes()['blue']['raw'];
        $this->times = array_fill_keys(array_keys($this->values), ($clock)() - 100000);
    }

    public function profiles(): array
    {
        return ['dark' => ['brightness' => 10, 'temperature' => 0, 'brightnessPct' => 1.0, 'temperaturePct' => 0.0],
            'action' => ['brightness' => 1000, 'temperature' => 332, 'brightnessPct' => 100.0, 'temperaturePct' => 33.0],
            'future-1' => ['brightness' => 200, 'temperature' => 1000, 'brightnessPct' => 20.0, 'temperaturePct' => 100.0],
            'future-6' => ['brightness' => 150, 'temperature' => 151, 'brightnessPct' => 15.0, 'temperaturePct' => 15.0],
            'encounters' => ['brightness' => 901, 'temperature' => 197, 'brightnessPct' => 90.0, 'temperaturePct' => 20.0]];
    }

    public function scenes(): array
    {
        $result = [];
        foreach (['blue' => 199, 'green' => 120, 'yellow' => 55] as $color => $hue) {
            $raw = sprintf('07373702%04x03e803e800000000', $hue);
            $result[$color] = ['raw' => $raw, 'source_sha256' => hash('sha256', $raw), 'source_header' => 7, 'value' => ['scene_num' => 8,
                'scene_units' => [['unit_switch_duration' => 55, 'unit_gradient_duration' => 55,
                    'unit_change_mode' => 'gradient', 'h' => $hue, 's' => 1000, 'v' => 1000, 'bright' => 0, 'temperature' => 0]]]];
        }

        return $result;
    }

    public function colours(): array
    {
        return ['detained' => ['h' => 25, 's' => 853, 'v' => 1000],
            'underworld' => ['h' => 250, 's' => 670, 'v' => 150]];
    }

    public function white(string $profile): void
    {
        $this->values = array_replace($this->values, [20 => true, 21 => 'white',
            22 => $this->profiles()[$profile]['brightness'], 23 => $this->profiles()[$profile]['temperature']]);
    }

    public function scene(string $color): void
    {
        $this->values = array_replace($this->values, [20 => true, 21 => 'scene', 25 => $this->scenes()[$color]['raw']]);
    }

    public function sendRealtime(array $channels): array
    {
        foreach (['h' => 360, 's' => 1000, 'v' => 1000, 'bright' => 1000, 'temperature' => 1000] as $field => $maximum) {
            if (! is_int($channels[$field]) || $channels[$field] < 0 || $channels[$field] > $maximum) {
                throw new \LogicException('Invalid raw channel');
            }
        }
        if ($channels['v'] === 0 && $channels['bright'] === 0) {
            throw new \LogicException('All-zero frame');
        }

        return $this->write(['operation' => 'realtime', 'channels' => $channels]);
    }

    public function sendCommands(array $commands): array
    {
        $updates = [];
        foreach ($commands as $command) {
            $dp = match ($command['code']) {
                'work_mode' => 21, 'bright_value_v2' => 22, 'temp_value_v2' => 23, 'scene_data_v2' => 25,
                default => throw new \LogicException('Unexpected command: power/DP35 are forbidden'),
            };
            $updates[$dp] = $dp === 25 ? $this->sceneRaw($command['value']) : $command['value'];
        }
        try {
            $receipt = $this->write(['operation' => 'commands', 'commands' => $commands, 'brightness' => $updates[22] ?? null]);
        } catch (TuyaCloudException $error) {
            if ($this->applyBeforeThrow) {
                $this->applyUpdates($updates);
            }
            throw $error;
        }
        $this->applyUpdates($updates);

        return $receipt;
    }

    public function sendStaticColour(array $channels): array
    {
        if ($channels['bright'] !== 0 || $channels['temperature'] !== 0 || $channels['v'] < 10) {
            throw new \LogicException('Invalid static colour');
        }
        $updates = [21 => 'colour', 24 => sprintf('%04x%04x%04x', $channels['h'], $channels['s'], $channels['v'])];
        try {
            $receipt = $this->write(['operation' => 'static_colour', 'channels' => $channels]);
        } catch (TuyaCloudException $error) {
            if ($this->applyBeforeThrow) {
                $this->applyUpdates($updates);
            }
            throw $error;
        }
        $this->applyUpdates($updates);

        return $receipt;
    }

    private function applyUpdates(array $updates): void
    {
        if (in_array(count($this->writes), $this->discardedWrites, true)) {
            return;
        }
        $this->values = array_replace($this->values, $updates);
        if (! $this->staleTimes) {
            foreach ($updates as $dp => $value) {
                $this->times[$dp] = ($this->clock)();
            }
        }
    }

    private function sceneRaw(array $value): string
    {
        foreach ($this->scenes() as $scene) {
            if ($scene['value'] === $value) {
                return $scene['raw'];
            }
        }
        $units = $value['scene_units'] ?? [];
        if (($value['scene_num'] ?? null) === 8 && count($units) === 2 && $units[0] === $units[1]
            && $units[0]['unit_change_mode'] === 'gradient') {
            $raw = '07';
            foreach ($units as $unit) {
                $raw .= sprintf('%02x%02x02%04x%04x%04x%04x%04x', $unit['unit_switch_duration'],
                    $unit['unit_gradient_duration'], $unit['h'], $unit['s'], $unit['v'], $unit['bright'], $unit['temperature']);
            }

            return $raw;
        }
        throw new \LogicException('Uncaptured scene');
    }

    private function write(array $write): array
    {
        ($this->beforeWrite ?? static fn () => null)();
        $this->writes[] = $write + ['at' => ($this->clock)()];
        if (count($this->writes) === $this->throwAt) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }
        ($this->afterWrite ?? static fn () => null)();

        return ['sentAt' => ($this->clock)(), 'acceptedAt' => ($this->clock)()];
    }
}

class ContinuousSnapshotFixture extends NativeCloudLightingDriver
{
    public function __construct(private ContinuousCloudFixture $fixture) {}

    public function profiles(): array
    {
        return $this->fixture->profiles();
    }

    public function scenes(): array
    {
        return $this->fixture->scenes();
    }

    public function colours(): array
    {
        return $this->fixture->colours();
    }

    public function readSnapshot(): array
    {
        $this->fixture->reads++;
        ($this->fixture->beforeRead ?? static fn () => null)();

        return ['values' => $this->fixture->values, 'times' => $this->fixture->times,
            'serverTime' => ($this->fixture->clock)(), 'receivedAt' => ($this->fixture->clock)()];
    }

    public function observationFromSnapshot(array $snapshot, int $now): array
    {
        return ['driver' => 'cloud', 'mode' => 'white', 'quality' => 'reported', 'physicalConfirmed' => false,
            'brightnessPct' => $snapshot['values'][22] / 10, 'temperaturePct' => $snapshot['values'][23] / 10, 'observedAt' => $now];
    }
}
