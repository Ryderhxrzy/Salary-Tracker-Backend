<?php

namespace App\Support;

final class Money
{
    public static function round(float|int|string|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round((float) $value, 2);
    }

    public static function sum(iterable $values): float
    {
        $total = 0.0;
        foreach ($values as $value) {
            $total += (float) ($value ?? 0);
        }

        return round($total, 2);
    }
}
