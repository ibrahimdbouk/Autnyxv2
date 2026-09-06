<?php

namespace App\Platform\Recommendation;

/**
 * P2.3 — a canonical prescriptive recommendation: not just "here's an anomaly"
 * but "here's the action to take, its expected value, how confident we are, and
 * the risk." Apps build this from their own recommendation logic; the
 * {@see DecisionEngine} scores it and executes it (through P2.1). Confidence and
 * risk are 0..1; expectedValue is money.
 */
class Recommendation
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $intentType,          // reorder | transfer | price_adjustment | …
        public readonly ?string $sku = null,
        public readonly ?int $storeId = null,
        public readonly ?float $quantity = null,
        public readonly ?string $targetDate = null,
        public readonly float $expectedValue = 0.0,  // upside if executed (money)
        public readonly float $confidence = 0.5,      // 0..1
        public readonly float $risk = 0.0,            // 0..1 downside probability
        public readonly ?string $rationale = null,
        public readonly ?string $objective = null,
        public readonly ?string $source = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id'      => $this->tenantId,
            'intent_type'    => $this->intentType,
            'sku'            => $this->sku,
            'store_id'       => $this->storeId,
            'quantity'       => $this->quantity,
            'target_date'    => $this->targetDate,
            'expected_value' => $this->expectedValue,
            'confidence'     => $this->confidence,
            'risk'           => $this->risk,
            'rationale'      => $this->rationale,
            'objective'      => $this->objective,
            'source'         => $this->source,
        ];
    }
}
