<?php

namespace App\Lighting;

/** Shared classification and timing for restriction and other-world encounters. */
class LightingTransitionPolicy
{
    public static function isEncounter(?array $target, ?array $settings = null): bool
    {
        if (! in_array($target['kind'] ?? null, ['white', 'colour'], true)
            || ! is_string($target['profile'] ?? null)) {
            return false;
        }
        $settings ??= config('lighting');

        return ($settings['presets'][$target['profile']]['transitionGroup'] ?? null) === 'encounter';
    }

    public static function encounterFadeInMs(?array $settings = null): int
    {
        $settings ??= config('lighting');

        return max(0, min(30000, (int) ($settings['encounter_fade_in_ms'] ?? 2500)));
    }

    public static function encounterMusicDelayMs(?array $settings = null): int
    {
        $settings ??= config('lighting');
        $multiplier = (float) ($settings['encounter_music_delay_multiplier'] ?? 0.6);
        $multiplier = is_finite($multiplier) ? max(0, $multiplier) : 0;

        return (int) round(self::encounterFadeInMs($settings) * $multiplier);
    }
}
