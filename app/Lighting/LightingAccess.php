<?php

namespace App\Lighting;

use Illuminate\Auth\Access\AuthorizationException;

/** One allowlist for the web UI, HTTP commands and persisted worker leases. */
class LightingAccess
{
    public static function allows(?int $userId): bool
    {
        return $userId !== null && $userId > 0
            && in_array((string) $userId, array_map('strval', config('lighting.allowed_user_ids', [])), true);
    }

    public static function authorize(int $userId): void
    {
        if (! self::allows($userId)) {
            throw new AuthorizationException('Lighting is not available for this account.');
        }
    }
}
