<?php

namespace Tests\Unit;

use App\Lighting\LightingPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LightingPlannerTest extends TestCase
{
    public static function curves(): array
    {
        return [['smoothstep'], ['linear']];
    }

    #[DataProvider('curves')]
    public function test_white_fade_is_monotone_from_current_level_and_never_exceeds_target(string $curve): void
    {
        $planner = new LightingPlanner;
        $state = [
            'desired_target' => ['kind' => 'mythos', 'color' => null, 'mythosSessionId' => 'test'],
            'observed' => ['mode' => 'white', 'brightnessPct' => 60.0, 'temperaturePct' => 20.0, 'rgbSuppressed' => true, 'quality' => 'simulated'],
            'color_barrier' => false, 'transition' => null, 'dark_since_ms' => null,
        ];
        $settings = ['white_fade_down_ms' => 5000, 'curve' => $curve];
        $previous = $state['observed'];
        for ($time = 0; $time < 5000; $time += 50) {
            $plan = $planner->next($state, $time, $settings);
            $command = $plan['command'];
            if ($command['operation'] === 'dark_anchor') {
                $this->assertTrue($plan['darkReached']);
                $this->assertGreaterThan(0, $time);

                return;
            }
            $this->assertLessThanOrEqual($previous['brightnessPct'], $command['brightnessPct']);
            $this->assertLessThanOrEqual($previous['temperaturePct'], $command['temperaturePct']);
            $this->assertGreaterThanOrEqual(1, $command['brightnessPct']);
            $this->assertGreaterThanOrEqual(0, $command['temperaturePct']);
            $previous = $command;
            $state['transition'] = $plan['transition'];
        }
        $this->fail('The fade did not reach its anchor within the configured duration.');
    }
}
