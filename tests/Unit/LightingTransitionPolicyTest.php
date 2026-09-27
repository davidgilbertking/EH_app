<?php

namespace Tests\Unit;

use App\Lighting\LightingTransitionPolicy;
use Tests\TestCase;

class LightingTransitionPolicyTest extends TestCase
{
    public function test_catalog_classifies_all_restrictions_and_worlds_including_white_exceptions(): void
    {
        $expected = ['detained', 'lost', 'carcosa', 'great-race', 'yuggoth', 'celaeno', 'leng',
            'dreamlands-gate', 'kadath', 'underworld', 'abyss'];
        foreach (['past', 'future'] as $period) {
            foreach (range(1, 8) as $number) {
                $expected[] = $period.'-'.$number;
            }
        }
        $actual = [];
        foreach (config('lighting.presets') as $alias => $profile) {
            if (LightingTransitionPolicy::isEncounter(['kind' => $profile['kind'], 'profile' => $alias])) {
                $actual[] = $alias;
            }
        }
        $this->assertEqualsCanonicalizing($expected, $actual);
        $this->assertTrue(LightingTransitionPolicy::isEncounter(['kind' => 'white', 'profile' => 'future-1']));
        $this->assertTrue(LightingTransitionPolicy::isEncounter(['kind' => 'white', 'profile' => 'future-6']));
        $this->assertFalse(LightingTransitionPolicy::isEncounter(['kind' => 'white', 'profile' => 'dreamlands-board']));
        $this->assertFalse(LightingTransitionPolicy::isEncounter(['kind' => 'white', 'profile' => 'encounters']));
        $this->assertFalse(LightingTransitionPolicy::isEncounter(['kind' => 'mythos', 'color' => 'green']));
        $this->assertFalse(LightingTransitionPolicy::isEncounter(null));
    }

    public function test_group_membership_is_configurable_and_does_not_depend_on_colour_mode(): void
    {
        $settings = ['presets' => ['custom-white' => ['transitionGroup' => 'encounter'],
            'custom-colour' => ['transitionGroup' => 'ordinary']]];
        $this->assertTrue(LightingTransitionPolicy::isEncounter(['kind' => 'white', 'profile' => 'custom-white'], $settings));
        $this->assertFalse(LightingTransitionPolicy::isEncounter(['kind' => 'colour', 'profile' => 'custom-colour'], $settings));
    }

    public function test_non_finite_music_multiplier_disables_delay(): void
    {
        foreach ([INF, NAN] as $multiplier) {
            $this->assertSame(0, LightingTransitionPolicy::encounterMusicDelayMs([
                'encounter_fade_in_ms' => 2500, 'encounter_music_delay_multiplier' => $multiplier,
            ]));
        }
    }
}
