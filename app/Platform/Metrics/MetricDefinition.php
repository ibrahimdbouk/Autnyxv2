<?php

namespace App\Platform\Metrics;

use Closure;

/**
 * P1.4 — a governed metric definition. One place per metric: its key, label,
 * unit, human description, a version (bump when the formula changes), and the
 * resolver that computes it for a tenant. Apps register their metrics into the
 * {@see MetricRegistry}; everything downstream reads values through
 * {@see MetricService} instead of recomputing the formula inline.
 */
class MetricDefinition
{
    public const UNIT_MONEY   = 'money';
    public const UNIT_PERCENT = 'percent';
    public const UNIT_COUNT   = 'count';
    public const UNIT_RATIO   = 'ratio';
    public const UNIT_DAYS    = 'days';

    /**
     * @param  Closure(int, array<string,mixed>): (int|float)  $resolver
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $unit,
        public readonly string $description,
        public readonly int $version,
        public readonly Closure $resolver,
    ) {
    }
}
