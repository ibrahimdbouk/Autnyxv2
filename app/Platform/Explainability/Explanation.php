<?php

namespace App\Platform\Explainability;

/**
 * P4.5 — the ONE canonical explanation shape every intelligence output speaks.
 * Whatever the app (Root-Cause, Assortment, …) or the producing layer, an
 * explanation carries the same fields: what it would do (headline / intent), what
 * it's worth (impact), how sure we are (confidence + label), the downside (risk),
 * the objective it serves, the evidence behind it, the reasons the confidence was
 * adjusted or the action routed, and the expected outcome. A consistent contract
 * is what lets a single "why" UI render every app's output — and what stops each
 * new app inventing its own format.
 *
 * Confidence-label thresholds match the P2.3 DecisionEngine so the whole platform
 * speaks one vocabulary.
 */
class Explanation
{
    /**
     * @param  array<int,string>  $evidence
     * @param  array<int,string>  $reasons
     */
    public function __construct(
        public readonly string $headline,
        public readonly string $intentType,
        public readonly float $impact,
        public readonly float $confidence,
        public readonly string $confidenceLabel,
        public readonly float $risk,
        public readonly ?string $objective = null,
        public readonly array $evidence = [],
        public readonly array $reasons = [],
        public readonly ?string $expectedOutcome = null,
    ) {
    }

    /** The shared confidence vocabulary (matches P2.3). */
    public static function labelFor(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.85 => 'established',
            $confidence >= 0.60 => 'probable',
            $confidence >= 0.35 => 'suspected',
            default             => 'unknown',
        };
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'headline'         => $this->headline,
            'intent_type'      => $this->intentType,
            'impact'           => $this->impact,
            'confidence'       => $this->confidence,
            'confidence_label' => $this->confidenceLabel,
            'risk'             => $this->risk,
            'objective'        => $this->objective,
            'evidence'         => $this->evidence,
            'reasons'          => $this->reasons,
            'expected_outcome' => $this->expectedOutcome,
        ];
    }
}
