<?php

namespace App\Lighting\Drivers;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class MockLightingDriver implements LightingDriver
{
    public function capabilities(): array
    {
        return [
            'readState' => true, 'setWhite' => true, 'setColour' => true, 'applyCapturedScene' => false,
            'sceneCapture' => false, 'nativeWhiteTransition' => false,
            'nativeTransitionCancellation' => false, 'safeSceneExit' => true,
            'orderedCommands' => true, 'transitionCompletionSignal' => true,
            'simulated' => true,
        ];
    }

    public function readState(?array $lastObservation, int $now): array
    {
        $this->transport();

        $observed = json_decode(DB::table('lighting_mock_devices')->where('id', 1)->value('state'), true, 512, JSON_THROW_ON_ERROR);
        $physical = array_flip(['mode', 'brightnessPct', 'temperaturePct', 'sceneLevel', 'color', 'rgbSuppressed', 'profile', 'hsv']);
        if (! isset($observed['lightingTarget']) && array_intersect_key($observed, $physical) == array_intersect_key($lastObservation ?? [], $physical)) {
            $observed['lightingTarget'] = $lastObservation['lightingTarget'] ?? null;
        }

        return array_merge($observed, ['quality' => 'simulated', 'driver' => 'mock', 'observedAt' => $now]);
    }

    public function execute(array $command, ?array $lastObservation, int $now): array
    {
        $this->transport();
        $observation = match ($command['operation']) {
            'white' => [
                'mode' => 'white', 'brightnessPct' => $command['brightnessPct'],
                'temperaturePct' => $command['temperaturePct'], 'rgbSuppressed' => true,
            ],
            'scene' => array_replace($this->scene($command['color'], $command['mythosSessionId']), ['sceneLevel' => $command['sceneLevel'] ?? 1.0]),
            'colour' => ['mode' => 'colour', 'profile' => $command['profile'], 'hsv' => $command['hsv'],
                'sceneLevel' => $command['hsv']['v'] / 1000, 'rgbSuppressed' => false],
            'scene_dim' => $this->dim($lastObservation ?? [], $command['sceneLevel']),
            'dark_anchor' => [
                'mode' => 'white', 'brightnessPct' => 1.0, 'temperaturePct' => 0.0, 'rgbSuppressed' => true,
            ],
            default => throw new RuntimeException('configuration_error'),
        };
        if ($command['operation'] === 'white' && isset($command['profile'])) {
            $observation['profile'] = $command['profile'];
        }

        $observation = array_merge($observation, [
            'quality' => 'simulated', 'driver' => 'mock', 'observedAt' => $now,
            'completion' => 'simulated_step',
            'lightingTarget' => $command['lightingTarget'] ?? $lastObservation['lightingTarget'] ?? null,
            'encounterTransition' => $command['encounterTransition'] ?? false,
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

    private function dim(array $observation, float $level): array
    {
        $observation['sceneLevel'] = $level;
        if (($observation['mode'] ?? null) === 'colour') {
            $observation['hsv']['v'] = max(10, (int) round($level * 1000));
        }

        return $observation;
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
