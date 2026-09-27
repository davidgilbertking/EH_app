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
use Tests\TestCase;

class LightingColourTest extends TestCase
{
    use RefreshDatabase;

    private int $user;

    private string $epoch;

    private int $sequence = 0;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['lighting.driver' => 'mock', 'lighting.white_fade_down_ms' => 100,
            'lighting.white_fade_up_ms' => 100, 'lighting.scene_fade_in_ms' => 200,
            'lighting.scene_fade_out_ms' => 200, 'lighting.dark_hold_ms' => 0]);
        $this->now = LightingStore::now();
        $this->user = User::factory()->create()->id;
        config()->set('lighting.allowed_user_ids', [$this->user]);
        $this->epoch = app(LightingControl::class)->control($this->user, 'colour-fixture', true, null)['controlEpoch'];
    }

    private function send(array $target): void
    {
        app(LightingControl::class)->intent($this->user, 'colour-fixture', [
            'intentId' => (string) Str::uuid(), 'controlEpoch' => $this->epoch,
            'clientSeq' => ++$this->sequence, 'target' => $target]);
    }

    private function finish(): LightingState
    {
        for ($i = 0; $i < 100; $i++) {
            $this->now += 50;
            app(LightingCoordinator::class)->tick($this->now);
            $state = LightingState::findOrFail(1);
            $this->assertNull($state->error);
            if ($state->revision === $state->applied_revision) {
                return $state;
            }
        }
        $this->fail('Mock colour transition did not finish.');
    }

    public function test_mock_colour_transitions_preserve_dark_barrier_and_exact_hsv(): void
    {
        $this->send(['kind' => 'colour', 'profile' => 'detained']);
        $state = $this->finish();
        $this->assertSame('colour_active', $state->stage);
        $this->assertSame(config('lighting.colour_profiles.detained'), $state->observed['hsv']);
        $this->assertTrue($state->color_barrier);
        $this->send(['kind' => 'colour', 'profile' => 'underworld']);
        $state = $this->finish();
        $this->assertSame(config('lighting.colour_profiles.underworld'), $state->observed['hsv']);
        $events = DB::table('lighting_events')->where('operation', 'colour')->orderBy('id')->get();
        $lastMinimum = $events->last(fn ($event) => json_decode($event->target, true)['profile'] === 'detained'
            && json_decode($event->target, true)['hsv']['v'] === 10)->id;
        $firstNewHue = $events->first(fn ($event) => $event->operation === 'colour'
            && json_decode($event->target, true)['profile'] === 'underworld');
        $this->assertLessThan($firstNewHue->id, $lastMinimum);
        $this->assertSame(10, json_decode($firstNewHue->target, true)['hsv']['v']);

        $this->send(['kind' => 'white', 'profile' => 'future-1']);
        $state = $this->finish();
        $this->assertSame('white_active', $state->stage);
        $this->assertSame(20, $state->observed['brightnessPct']);
        $this->assertSame(100, $state->observed['temperaturePct']);
        $this->assertFalse($state->color_barrier);
    }

    public function test_legacy_cloud_driver_rejects_colour_without_reading_or_writing_the_lamp(): void
    {
        config()->set('lighting.driver', 'cloud');
        $driver = $this->createMock(LightingDriver::class);
        $driver->expects($this->once())->method('capabilities')->willReturn(['simulated' => false, 'setColour' => false]);
        $driver->expects($this->never())->method('readState');
        $driver->expects($this->never())->method('execute');
        app()->instance(LightingDriver::class, $driver);
        $this->send(['kind' => 'colour', 'profile' => 'detained']);
        app(LightingCoordinator::class)->tick($this->now);
        $this->assertSame('unsupported_transition', LightingState::findOrFail(1)->error);
    }
}
