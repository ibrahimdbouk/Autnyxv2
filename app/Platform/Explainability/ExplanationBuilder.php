<?php

namespace App\Platform\Explainability;

use App\Platform\Orchestration\OrchestrationOutcome;
use App\Platform\Recommendation\Recommendation;

/**
 * P4.5 — assemble a canonical {@see Explanation} from the pieces the platform
 * already produces. Seed it from a Recommendation (P2.3), then layer in the
 * data-quality reasons (P4.2), similar-case evidence (P4.3), calibration (P4.4)
 * and orchestration routing (P4.8) — each contributes to the same object rather
 * than its own bespoke format. Fluent; build() computes the shared confidence
 * label from the final (possibly adjusted) confidence.
 */
class ExplanationBuilder
{
    private string $headline;
    private string $intentType;
    private float $impact;
    private float $confidence;
    private float $risk;
    private ?string $objective;
    /** @var array<int,string> */
    private array $evidence = [];
    /** @var array<int,string> */
    private array $reasons = [];
    private ?string $expectedOutcome = null;

    private function __construct(Recommendation $rec)
    {
        $this->headline = $this->headlineFor($rec);
        $this->intentType = $rec->intentType;
        $this->impact = $rec->expectedValue;
        $this->confidence = $rec->confidence;
        $this->risk = $rec->risk;
        $this->objective = $rec->objective;

        if ($rec->rationale !== null) {
            $this->evidence[] = $rec->rationale;
        }
    }

    public static function from(Recommendation $rec): self
    {
        return new self($rec);
    }

    /** Build straight from an orchestration result: adopts its effective confidence + reasons. */
    public static function fromOrchestration(Recommendation $rec, OrchestrationOutcome $outcome): Explanation
    {
        return self::from($rec)
            ->withConfidence($outcome->effectiveConfidence)
            ->addReasons($outcome->reasons)
            ->withExpectedOutcome("Orchestration: {$outcome->mode} (autonomy: {$outcome->level})")
            ->build();
    }

    public function withConfidence(float $confidence): self
    {
        $this->confidence = $confidence;

        return $this;
    }

    /** @param array<int,string> $evidence */
    public function addEvidence(array $evidence): self
    {
        $this->evidence = array_merge($this->evidence, array_values($evidence));

        return $this;
    }

    /** @param array<int,string> $reasons */
    public function addReasons(array $reasons): self
    {
        $this->reasons = array_merge($this->reasons, array_values($reasons));

        return $this;
    }

    public function withExpectedOutcome(string $expectedOutcome): self
    {
        $this->expectedOutcome = $expectedOutcome;

        return $this;
    }

    public function build(): Explanation
    {
        return new Explanation(
            headline: $this->headline,
            intentType: $this->intentType,
            impact: $this->impact,
            confidence: $this->confidence,
            confidenceLabel: Explanation::labelFor($this->confidence),
            risk: $this->risk,
            objective: $this->objective,
            evidence: $this->evidence,
            reasons: $this->reasons,
            expectedOutcome: $this->expectedOutcome,
        );
    }

    private function headlineFor(Recommendation $rec): string
    {
        $parts = [ucfirst(str_replace('_', ' ', $rec->intentType))];
        if ($rec->sku !== null) {
            $parts[] = $rec->sku;
        }
        if ($rec->quantity !== null) {
            $q = $rec->quantity;
            $qs = ($q == (int) $q) ? (string) (int) $q : (string) $q;
            $parts[] = "({$qs} units)";
        }

        return implode(' ', $parts);
    }
}
