<?php

namespace App\Platform\Metrics;

use Illuminate\Support\Carbon;

/**
 * P1.4 — the computed result of a metric: the number plus the governance metadata
 * (unit, definition version, when it was computed) so a caller always knows what
 * it's looking at and which formula version produced it.
 */
class MetricValue
{
    public function __construct(
        public readonly string $key,
        public readonly float $value,
        public readonly string $unit,
        public readonly int $version,
        public readonly Carbon $computedAt,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'value'       => $this->value,
            'unit'        => $this->unit,
            'version'     => $this->version,
            'computed_at' => $this->computedAt->toIso8601String(),
        ];
    }
}
