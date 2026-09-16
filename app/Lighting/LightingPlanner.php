<?php

namespace App\Lighting;

/** Pure transition calculation; it neither writes storage nor contacts a device. */
class LightingPlanner
{
    public function next(array $state, int $now, array $settings): array
    {
        $target = $state['desired_target'];
        $observed = $state['observed'] ?? [];
        $barrier = $state['color_barrier'] || ($observed['mode'] ?? 'unknown') === 'scene';
        $dark = $this->isDark($observed);

        if ($target['kind'] === 'mythos' && $target['color'] !== null
            && ($observed['mode'] ?? null) === 'scene'
            && ($observed['color'] ?? null) === $target['color'] && $state['transition'] === null
            && ($observed['mythosSessionId'] ?? null) === $target['mythosSessionId']
            && ($observed['sceneLevel'] ?? 0) >= 1.0
            && $this->isSettled($observed)) {
            return ['stage' => 'scene_active', 'complete' => true, 'transition' => null];
        }

        if ($barrier || ($target['kind'] === 'mythos' && ! $dark)) {
            $kind = ($observed['mode'] ?? 'unknown') === 'white' ? 'dark_white' : 'dark_scene';

            return $this->fade($state, $kind, ['brightnessPct' => 1.0, 'temperaturePct' => 0.0], $now, $settings);
        }

        if ($target['kind'] === 'mythos') {
            if ($target['color'] === null) {
                return ['stage' => 'dark', 'complete' => true, 'transition' => null];
            }
            if ($now - ($state['dark_since_ms'] ?? $now) < max(0, $settings['dark_hold_ms'])) {
                return ['stage' => 'dark', 'complete' => false, 'transition' => null];
            }

            return [
                'stage' => 'applying_scene', 'complete' => true, 'transition' => null,
                'command' => ['operation' => 'scene', 'color' => $target['color'], 'mythosSessionId' => $target['mythosSessionId']],
                'afterStage' => 'scene_active',
            ];
        }

        if ($dark && $state['dark_since_ms'] !== null && $now - $state['dark_since_ms'] < max(0, $settings['dark_hold_ms'])) {
            return ['stage' => 'dark', 'complete' => false, 'transition' => null];
        }

        $white = $settings['white_profiles'][$target['profile']];
        if (! $barrier && ($observed['mode'] ?? null) === 'white'
            && ($observed['rgbSuppressed'] ?? false) === true
            && $this->isSettled($observed)
            && abs(($observed['brightnessPct'] ?? -1) - $white['brightnessPct']) < 0.00001
            && abs(($observed['temperaturePct'] ?? -1) - $white['temperaturePct']) < 0.00001) {
            return ['stage' => 'white_active', 'complete' => true, 'transition' => null];
        }

        return $this->fade($state, 'white', $white, $now, $settings);
    }

    public function isDark(array $observed): bool
    {
        return ($observed['mode'] ?? null) === 'white'
            && ($observed['rgbSuppressed'] ?? false) === true
            && abs(($observed['brightnessPct'] ?? -1) - 1) < 0.00001
            && abs($observed['temperaturePct'] ?? -1) < 0.00001
            && $this->isSettled($observed);
    }

    public function isSettled(array $observed): bool
    {
        // Cloud acknowledgement and stored DP22/23 alone cannot prove that a
        // transient DP28 transition has ended. The driver must qualify readback.
        return ($observed['quality'] ?? null) === 'simulated'
            || (($observed['quality'] ?? null) === 'confirmed'
                && ($observed['outputSettled'] ?? false) === true
                && ($observed['completion'] ?? null) === 'cloud_readback');
    }

    public function isWhiteOrigin(array $observed): bool
    {
        // Reported/commanded values are useful interpolation origins, never
        // proof of darkness or completion. The RGB barrier remains independent.
        return ($observed['mode'] ?? null) === 'white'
            && ($observed['rgbSuppressed'] ?? false) === true
            && in_array($observed['quality'] ?? null, ['simulated', 'confirmed', 'reported', 'commanded'], true);
    }

    private function fade(array $state, string $kind, array $destination, int $now, array $settings): array
    {
        $observed = $state['observed'] ?? [];
        $transition = $state['transition'];
        $key = $kind.':'.json_encode($destination);
        if (($transition['key'] ?? null) !== $key) {
            $from = [
                'brightnessPct' => (float) ($observed['brightnessPct'] ?? 1),
                'temperaturePct' => (float) ($observed['temperaturePct'] ?? 0),
                'sceneLevel' => (float) ($observed['sceneLevel'] ?? 1),
            ];
            $distance = $kind === 'dark_scene' ? $from['sceneLevel'] : max(
                abs($destination['brightnessPct'] - $from['brightnessPct']) / 99,
                abs($destination['temperaturePct'] - $from['temperaturePct']) / 33,
            );
            $duration = match ($kind) {
                'dark_scene' => $settings['scene_fade_out_ms'],
                'dark_white' => $settings['white_fade_down_ms'],
                default => $settings['white_fade_up_ms'],
            };
            $transition = ['key' => $key, 'from' => $from, 'startedAt' => $now, 'duration' => max(0, (int) round($duration * min(1, $distance)))];
        }

        $fraction = $transition['duration'] === 0 ? 1.0 : min(1.0, max(0.0, ($now - $transition['startedAt']) / $transition['duration']));
        $eased = match ($settings['curve'] ?? 'smoothstep') {
            'smoothstep' => $fraction * $fraction * (3 - 2 * $fraction),
            'linear' => $fraction,
            default => throw new \InvalidArgumentException('configuration_error'),
        };
        $finished = $fraction >= 1;
        $stage = match ($kind) {
            'dark_scene' => 'fading_scene_down',
            'dark_white' => 'fading_white_down',
            default => 'fading_white_up',
        };
        if ($kind === 'dark_scene') {
            $command = $finished ? ['operation' => 'dark_anchor'] : [
                'operation' => 'scene_dim', 'sceneLevel' => $transition['from']['sceneLevel'] * (1 - $eased),
            ];
        } else {
            $command = ['operation' => 'white', 'commit' => $finished];
            foreach (['brightnessPct', 'temperaturePct'] as $field) {
                $command[$field] = $transition['from'][$field] + ($destination[$field] - $transition['from'][$field]) * $eased;
            }
            if ($finished && $kind === 'dark_white') {
                $command = ['operation' => 'dark_anchor'];
            }
        }

        return [
            'stage' => $stage, 'command' => $command, 'transition' => $finished ? null : $transition,
            'requiresSafeSceneExit' => $kind === 'dark_scene',
            'complete' => $finished && $kind === 'white',
            'darkReached' => $finished && $kind !== 'white',
            'afterStage' => $finished ? ($kind === 'white' ? 'white_active' : 'dark') : $stage,
        ];
    }
}
