<?php

namespace App\Lighting\Drivers;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class MockLightingDriver implements LightingDriver
{
    public function capabilities(): array
    {
        return [
            'readState' => true, 'setWhite' => true, 'applyCapturedScene' => false,
            'sceneCapture' => false, 'nativeWhiteTransition' => false,
            'nativeTransitionCancellation' => false, 'safeSceneExit' => true,
            'orderedCommands' => true, 'transitionCompletionSignal' => true,
            'simulated' => true,
        ];
    }

    public function readState(?array $lastObservation, int $now): array
    {
        $this->transport();

        return array_merge(json_decode(DB::table('lighting_mock_devices')->where('id', 1)->value('state'), true, 512, JSON_THROW_ON_ERROR),
            ['quality' => 'simulated', 'driver' => 'mock', 'observedAt' => $now]);
    }

    public function execute(array $command, ?array $lastObservation, int $now): array
    {
        $this->transport();
        $observation = match ($command['operation']) {
            'white' => [
                'mode' => 'white', 'brightnessPct' => $command['brightnessPct'],
                'temperaturePct' => $command['temperaturePct'], 'rgbSuppressed' => true,
            ],
            'scene' => $this->scene($command['color'], $command['mythosSessionId']),
            'scene_dim' => array_merge($lastObservation ?? [], ['sceneLevel' => $command['sceneLevel']]),
            'dark_anchor' => [
                'mode' => 'white', 'brightnessPct' => 1.0, 'temperaturePct' => 0.0, 'rgbSuppressed' => true,
            ],
            default => throw new RuntimeException('configuration_error'),
        };

        $observation = array_merge($observation, [
            'quality' => 'simulated', 'driver' => 'mock', 'observedAt' => $now,
            'completion' => 'simulated_step',
        ]);
        DB::table('lighting_mock_devices')->where('id', 1)->update(['state' => json_encode($observation, JSON_THROW_ON_ERROR)]);

        return $observation;
    }

    private function scene(string $color, string $mythosSessionId): array
    {
        if (! config("lighting.mock.scenes.{$color}")) {
            throw new RuntimeException('preset_not_configured');
        }

        return ['mode' => 'scene', 'color' => $color, 'sceneLevel' => 1.0, 'rgbSuppressed' => false, 'mythosSessionId' => $mythosSessionId];
    }

    private function transport(): void
    {
        // A bounded worker-only delay allows exercising cancellation during I/O.
        $delay = min(2000, max(0, (int) config('lighting.mock.delay_ms', 0)));
        if ($delay > 0) {
            usleep($delay * 1000);
        }
        if (in_array(config('lighting.mock.failure'), ['offline', 'transport_timeout'], true)) {
            throw new RuntimeException(config('lighting.mock.failure'));
        }
    }
}
