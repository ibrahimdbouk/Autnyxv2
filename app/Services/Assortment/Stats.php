<?php

namespace App\Services\Assortment;

/** Small, dependency-free order statistics for the peer benchmark. */
final class Stats
{
    /** Linear-interpolated percentile (p in 0..1) of a list; null when empty. */
    public static function percentile(array $values, float $p): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && is_finite((float) $v)));
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        if ($n === 1) {
            return (float) $values[0];
        }
        $pos = max(0.0, min(1.0, $p)) * ($n - 1);
        $lo  = (int) floor($pos);
        $hi  = (int) ceil($pos);

        return (float) $values[$lo] + ($pos - $lo) * ((float) $values[$hi] - (float) $values[$lo]);
    }

    public static function median(array $values): ?float
    {
        return self::percentile($values, 0.5);
    }

    /** (p75 - p25) / median — how much the peers disagree; null when undefined. */
    public static function spread(array $values): ?float
    {
        $med = self::median($values);
        if ($med === null || $med <= 0) {
            return null;
        }

        return (self::percentile($values, 0.75) - self::percentile($values, 0.25)) / $med;
    }
}
