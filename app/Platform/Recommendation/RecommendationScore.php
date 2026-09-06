<?php

namespace App\Platform\Recommendation;

/**
 * P2.3 — the scored form of a recommendation. Priority combines the three levers
 * the DoD calls for: expected value, confidence, and risk.
 *   riskAdjustedValue = expectedValue × (1 − risk)
 *   priority          = riskAdjustedValue × confidence
 * so a big-but-uncertain-and-risky call is ranked below a smaller sure thing.
 */
class RecommendationScore
{
    public function __construct(
        public readonly float $expectedValue,
        public readonly float $confidence,
        public readonly float $risk,
        public readonly float $riskAdjustedValue,
        public readonly float $priority,
        public readonly string $confidenceLabel,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'expected_value'      => $this->expectedValue,
            'confidence'          => $this->confidence,
            'confidence_label'    => $this->confidenceLabel,
            'risk'                => $this->risk,
            'risk_adjusted_value' => $this->riskAdjustedValue,
            'priority'            => $this->priority,
        ];
    }
}
