<?php

namespace App\Platform\Trust;

/**
 * P4.2 — the result of discounting a confidence by data quality: the base
 * confidence, the quality factor applied (0..1), the adjusted confidence, and the
 * human reasons for any discount. The reasons are the point — a low number the
 * user can't interrogate erodes trust; "confidence lowered because sales_daily is
 * stale" builds it.
 */
class ConfidenceAdjustment
{
    /**
     * @param  array<int,string>  $reasons
     */
    public function __construct(
        public readonly float $base,
        public readonly float $factor,
        public readonly float $adjusted,
        public readonly array $reasons = [],
    ) {
    }

    public function wasDiscounted(): bool
    {
        return $this->factor < 1.0;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'base'           => $this->base,
            'factor'         => $this->factor,
            'adjusted'       => $this->adjusted,
            'was_discounted' => $this->wasDiscounted(),
            'reasons'        => $this->reasons,
        ];
    }
}
