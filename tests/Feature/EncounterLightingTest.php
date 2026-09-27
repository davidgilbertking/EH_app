<?php

namespace Tests\Feature;

use App\Lighting\Drivers\LightingDriver;
use App\Lighting\LightingControl;
use App\Lighting\LightingCoordinator;
use App\Lighting\LightingStore;
use App\Models\LightingState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EncounterLightingTest extends TestCase
{
    use RefreshDatabase;

    private int $user;

    private string $epoch;

    private int $sequence = 0;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['lighting.driver' => 'mock', 'lighting.white_fade_down_ms' => 4000,
            'lighting.white_fade_up_ms' => 7000, 'lighting.scene_fade_in_ms' => 8000,
            'lighting.scene_fade_out_ms' => 6000, 'lighting.dark_hold_ms' => 1000,
            'lighting.encounter_fade_in_ms' => 2500, 'lighting.curve' => 'linear']);
        $this->now = LightingStore::now();
        $this->user = User::factory()->create()->id;
        config()->set('lighting.allowed_user_ids', [$this->user]);
        $this->epoch = app(LightingControl::class)->control($this->user, 'encounter-fixture', true, null)['controlEpoch'];
    }

    public static function encounterTargets(): array
    {
        return [['colour', 'detained'], ['colour', 'underworld'], ['white', 'future-1'], ['white', 'future-6']];
    }

    #[DataProvider('encounterTargets')]
    public function test_entering_encounter_lowers_immediately_then_rises_for_exactly_2500_ms(string $kind, string $profile): void
    {
        $this->send(['kind' => $kind, 'profile' => $profile]);
        $this->tick(); // Read the current white output.
        $state = $this->tick();
        $this->assertSame('encounter_minimum', $state->stage);
        $this->assertEquals(1, $state->observed['brightnessPct']);
        $this->assertEquals(33, $state->observed['temperaturePct']);
        $state = $this->tick();
        $startedAt = $state->transition['startedAt'];
        $this->assertSame(2500, $state->transition['duration']);
        if ($kind === 'white') {
            $this->assertEquals(1, $state->observed['brightnessPct']);
            $this->assertEquals(config('lighting.white_profiles.'.$profile.'.temperaturePct'), $state->observed['temperaturePct']);
        } else {
            $hsv = config('lighting.colour_profiles.'.$profile);
            $this->assertSame(array_replace($hsv, ['v' => 10]), $state->observed['hsv']);
        }
        $this->tickAt($startedAt + 2499);
        $state = LightingState::findOrFail(1);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $state = $this->tickAt($startedAt + 2500);
        $this->assertSame($state->revision, $state->applied_revision);
        $this->assertSame(['kind' => $kind, 'profile' => $profile], $state->observed['lightingTarget']);
        $this->assertFalse($state->observed['encounterTransition']);
        if ($kind === 'white') {
            $this->assertFalse($state->color_barrier);
            $this->assertEquals(config('lighting.white_profiles.'.$profile.'.brightnessPct'), $state->observed['brightnessPct']);
        } else {
            $this->assertSame(config('lighting.colour_profiles.'.$profile), $state->observed['hsv']);
        }
    }

    public function test_leaving_white_future_changes_temperature_only_at_minimum_and_same_target_is_no_op(): void
    {
        $this->send(['kind' => 'white', 'profile' => 'future-6']);
        $this->finish();
        $commands = $this->commandCount();
        $this->send(['kind' => 'white', 'profile' => 'future-6']);
        $this->finish();
        $this->assertSame($commands, $this->commandCount());

        $this->send(['kind' => 'white', 'profile' => 'victory']);
        $this->tick();
        $state = $this->tick();
        $this->assertEquals(1, $state->observed['brightnessPct']);
        $this->assertEquals(15, $state->observed['temperaturePct']);
        $state = $this->tick();
        $this->assertEquals(1, $state->observed['brightnessPct']);
        $this->assertEquals(20, $state->observed['temperaturePct']);
        $this->assertSame(2500, $state->transition['duration']);
        $state = $this->tickAt($state->transition['startedAt'] + 2500);
        $this->assertSame($state->revision, $state->applied_revision);
        $this->assertSame('victory', $state->observed['lightingTarget']['profile']);
    }

    public function test_retarget_and_restart_keep_encounter_policy_and_lower_old_hue_before_white(): void
    {
        $this->send(['kind' => 'colour', 'profile' => 'underworld']);
        $this->tick();
        $this->tick();
        $state = $this->tick();
        $this->tickAt($state->transition['startedAt'] + 1000);
        $this->send(['kind' => 'white', 'profile' => 'action']);
        $this->tick();
        $state = $this->tick();
        $this->assertSame('colour', $state->observed['mode']);
        $this->assertSame(['h' => 250, 's' => 670, 'v' => 10], $state->observed['hsv']);
        $state = $this->tick();
        $this->assertSame('white', $state->observed['mode']);
        $this->assertEquals(1, $state->observed['brightnessPct']);
        $this->assertEquals(33, $state->observed['temperaturePct']);
        $startedAt = $state->transition['startedAt'];
        app(LightingCoordinator::class)->recover();
        $state = $this->tick();
        $this->assertSame($startedAt, $state->transition['startedAt']);
        $this->assertSame(2500, $state->transition['duration']);
        $state = $this->tickAt($startedAt + 2499);
        $this->assertNotSame($state->revision, $state->applied_revision);
        $state = $this->tickAt($startedAt + 2500);
        $this->assertSame($state->revision, $state->applied_revision);
        $this->assertFalse($state->color_barrier);
    }

    public function test_retarget_after_minimum_keeps_policy_even_before_encounter_hue_is_applied(): void
    {
        $this->send(['kind' => 'colour', 'profile' => 'detained']);
        $this->tick();
        $this->tick();
        $this->send(['kind' => 'white', 'profile' => 'action']);
        app(LightingCoordinator::class)->recover();
        $this->tick();
        $this->tick();
        $state = $this->tick();
        $this->assertSame(2500, $state->transition['duration']);
        $this->assertSame('white', $state->observed['mode']);
        $this->assertEquals(1, $state->observed['brightnessPct']);
        $this->assertSame(0, DB::table('lighting_events')->where('operation', 'colour')->count());
        $state = $this->tickAt($state->transition['startedAt'] + 2500);
        $this->assertSame($state->revision, $state->applied_revision);
    }

    public function test_ordinary_white_transition_keeps_original_distance_based_timing(): void
    {
        $this->send(['kind' => 'white', 'profile' => 'combat']);
        $this->tick();
        $state = $this->tick();
        $this->assertSame('fading_white_up', $state->stage);
        $this->assertSame(3818, $state->transition['duration']);
        $this->assertArrayNotHasKey('policy', $state->transition);
        $this->assertEquals(100, $state->observed['brightnessPct']);
    }

    public function test_legacy_physical_executor_rejects_white_encounter_before_read_or_write(): void
    {
        config()->set('lighting.driver', 'cloud');
        $driver = $this->createMock(LightingDriver::class);
        $driver->method('capabilities')->willReturn(['simulated' => false, 'setColour' => false]);
        $driver->expects($this->never())->method('readState');
        $driver->expects($this->never())->method('execute');
        app()->instance(LightingDriver::class, $driver);
        $this->send(['kind' => 'white', 'profile' => 'future-1']);
        app(LightingCoordinator::class)->tick($this->now);
        $this->assertSame('unsupported_transition', LightingState::findOrFail(1)->error);
    }

    private function send(array $target): void
    {
        app(LightingControl::class)->intent($this->user, 'encounter-fixture', [
            'intentId' => (string) Str::uuid(), 'controlEpoch' => $this->epoch,
            'clientSeq' => ++$this->sequence, 'target' => $target]);
    }

    private function tick(int $advance = 10): LightingState
    {
        return $this->tickAt($this->now + $advance);
    }

    private function tickAt(int $now): LightingState
    {
        $this->now = $now;
        app(LightingCoordinator::class)->tick($now);
        $state = LightingState::findOrFail(1);
        $this->assertNull($state->error);

        return $state;
    }

    private function finish(): LightingState
    {
        for ($i = 0; $i < 100; $i++) {
            $state = $this->tick(100);
            if ($state->revision === $state->applied_revision) {
                return $state;
            }
        }
        $this->fail('Encounter transition did not finish.');
    }

    private function commandCount(): int
    {
        return DB::table('lighting_events')->whereIn('operation', ['white', 'colour', 'scene', 'scene_dim', 'dark_anchor'])->count();
    }
}
