<?php

namespace App\Platform\Explainability;

/**
 * P4.5 — the ONE canonical explanation shape every intelligence output emits,
 * whatever app produced it. It unifies the pieces the platform already generates
 * separately — impact/confidence/risk (P2.3), data-quality reasons (P4.2),
 * similar-case evidence (P4.3), calibration (P4.4), orchestration routing (P4.8) —
 * into a single {headline, impact, confidence, confidence_label, risk, objective,
 * evidence, reasons, expected_outcome} object. A surface (or the future copilot)
 * renders "why" the same way across every app instead of one bespoke format each.
 */
class Explanation
{
    /**
     * @param  array<int,string>  $evidence  the "why" facts (signals, similar past cases)
     * @param  array<int,string>  $reasons   confidence adjustments / routing notes
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

    /**
     * Canonical confidence label — identical thresholds to the P2.3 DecisionEngine,
     * so a label means the same thing everywhere.
     */
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
