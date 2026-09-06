<?php

namespace App\Platform\Trust;

use App\Models\ContractViolation;
use Illuminate\Support\Collection;

/**
 * P4.2 — propagate data quality into recommendation confidence. When the data
 * feeding a recommendation has open data-contract breaches (P3.4), the
 * recommendation's confidence (P2.3) is automatically discounted, with the reason
 * attached. "Inventory data is unreliable for these stores → inventory
 * recommendations are low-confidence right now" — the enterprise-trust move.
 *
 * Today it reads open contract violations for the feeds in play; a data_health
 * score can be folded in the same way when wired (a later consumer step).
 */
class DataConfidence
{
    /** How much each breach kind erodes confidence (subtracted from a 1.0 base). */
    private const PENALTY = [
        ContractViolation::KIND_EMPTY           => 0.50,
        ContractViolation::KIND_STALE           => 0.30,
        ContractViolation::KIND_MISSING_COLUMNS => 0.30,
        ContractViolation::KIND_BELOW_MIN_ROWS  => 0.15,
    ];

    private const FLOOR = 0.20; // never zero it out — a discounted signal is still a signal

    /**
     * Data-quality factor in [FLOOR, 1.0] for a set of feeds: 1.0 = clean.
     *
     * @param  array<int,string>  $feedKeys
     */
    public function qualityFactor(int $tenantId, array $feedKeys): float
    {
        $penalty = $this->openViolations($tenantId, $feedKeys)
            ->sum(fn (ContractViolation $v) => self::PENALTY[$v->kind] ?? 0.1);

        return round(max(self::FLOOR, 1.0 - $penalty), 4);
    }

    /**
     * Human-readable reasons for the discount, one per open breach.
     *
     * @param  array<int,string>  $feedKeys
     * @return array<int,string>
     */
    public function reasons(int $tenantId, array $feedKeys): array
    {
        return $this->openViolations($tenantId, $feedKeys)
            ->map(fn (ContractViolation $v) => "{$v->feed_key}: {$v->kind}")
            ->values()
            ->all();
    }

    /**
     * Adjust a base confidence (0..1) by the quality of the feeds it depends on.
     *
     * @param  array<int,string>  $feedKeys
     */
    public function adjust(float $baseConfidence, int $tenantId, array $feedKeys): ConfidenceAdjustment
    {
        $factor = $this->qualityFactor($tenantId, $feedKeys);

        return new ConfidenceAdjustment(
            base: $baseConfidence,
            factor: $factor,
            adjusted: round($baseConfidence * $factor, 4),
            reasons: $this->reasons($tenantId, $feedKeys),
        );
    }

    /** @return Collection<int,ContractViolation> */
    private function openViolations(int $tenantId, array $feedKeys): Collection
    {
        return ContractViolation::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('feed_key', $feedKeys)
            ->whereNull('resolved_at')
            ->get();
    }
}
