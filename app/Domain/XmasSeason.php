<?php

namespace App\Domain;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * New Year season: two weeks before and two weeks after January 1st.
 * While active, the header Action / Muted Action buttons play Christmas
 * folders and the UI gets snowflakes + falling snow.
 */
final class XmasSeason
{
    public const DAYS_AROUND_NEW_YEAR = 14;
    public const MODE_COOKIE = 'eh_xmas_mode';

    /** Regular slug => Christmas replacement folder. */
    public const FOLDERS = [
        'action' => 'action-xmas',
        'action-muted' => 'action-muted-xmas',
    ];

    public static function isActive(?CarbonInterface $now = null): bool
    {
        // A manual choice on this device takes precedence over the calendar
        // and the deployment-wide demo setting.
        $manual = request()->cookie(self::MODE_COOKIE);
        if ($manual === 'on' || $manual === 'off') {
            return $manual === 'on';
        }

        // EH_XMAS_SEASON=true/false forces the season on/off (demo, rollback).
        $forced = config('eh.xmas_season');
        if (is_bool($forced)) {
            return $forced;
        }

        $tz = (string) config('eh.xmas_timezone', 'Europe/Moscow');
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($tz);
        $newYear = CarbonImmutable::create($now->month >= 7 ? $now->year + 1 : $now->year, 1, 1, 0, 0, 0, $tz);

        return $now->greaterThanOrEqualTo($newYear->subDays(self::DAYS_AROUND_NEW_YEAR))
            && $now->lessThan($newYear->addDays(self::DAYS_AROUND_NEW_YEAR));
    }

    public static function folderFor(string $slug): ?string
    {
        return self::FOLDERS[$slug] ?? null;
    }
}
