<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * A per-user counter that changes whenever something that affects past salaries
 * changes (attendance, leave, adjustments, salary settings, schedule). Cached money
 * figures (salary credited to a wallet) include it in their key, so they are
 * recomputed only after such a change instead of on every request.
 */
final class MoneyVersion
{
    public static function key(int $userId): string
    {
        return "money-version:{$userId}";
    }

    public static function get(int $userId): int
    {
        return (int) Cache::get(self::key($userId), 1);
    }

    public static function bump(?int $userId): void
    {
        if (! $userId) {
            return;
        }
        Cache::forever(self::key($userId), self::get($userId) + 1);
    }
}
