<?php

namespace App\Platform\Explainability;

use App\Platform\Orchestration\OrchestrationOutcome;
use App\Platform\Recommendation\Recommendation;

/**
 * P4.5 — assembles a canonical {@see Explanation} from the platform's own pieces.
 * Apps seed it from a Recommendation and fold in whatever context they have
 * (data-quality reasons, similar-case evidence, calibration, orchestration
 * routing); build() computes the confidence label. The point is that every app
 * produces the SAME explanation shape via this one builder, not a bespoke format.
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
        $this->intentType = $rec->intentType;
        $this->impact = $rec->expectedValue;
        $this->confidence = $rec->confidence;
        $this->risk = $rec->risk;
        $this->objective = $rec->objective;
        $this->headline = $this->headlineFor($rec);

        if ($rec->rationale !== null && $rec->rationale !== '') {
            $this->evidence[] = $rec->rationale;
        }
    }

    public static function from(Recommendation $rec): self
    {
        return new self($rec);
    }

    public function withHeadline(string $headline): self
    {
        $this->headline = $headline;
        return $this;
    }

    /** Override the effective confidence (e.g. after data-quality + memory adjustment). */
    public function withConfidence(float $confidence): self
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function addEvidence(string ...$evidence): self
    {
        array_push($this->evidence, ...$evidence);
        return $this;
    }

    /**
     * @param  array<int,string>  $reasons
     */
    public function addReasons(array $reasons): self
    {
        array_push($this->reasons, ...array_values($reasons));
        return $this;
    }

    public function withExpectedOutcome(string $outcome): self
    {
        $this->expectedOutcome = $outcome;
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

    /**
     * Convenience: turn a P4.8 orchestration result into a canonical explanation —
     * the effective (data + memory adjusted) confidence and every routing reason,
     * with the outcome mode as the expected outcome.
     */
    public static function fromOrchestration(Recommendation $rec, OrchestrationOutcome $outcome): Explanation
    {
        return self::from($rec)
            ->withConfidence($outcome->effectiveConfidence)
            ->addReasons($outcome->reasons)
            ->withExpectedOutcome(self::outcomeNarrative($outcome))
            ->build();
    }

    private static function outcomeNarrative(OrchestrationOutcome $outcome): string
    {
        return match ($outcome->mode) {
            OrchestrationOutcome::MODE_EXECUTED => 'Auto-executed.',
            OrchestrationOutcome::MODE_QUEUED   => 'Sent for human approval.',
            OrchestrationOutcome::MODE_BLOCKED  => 'Blocked by a guardrail.',
            default                             => 'Advice only.',
        };
    }

    private function headlineFor(Recommendation $rec): string
    {
        $parts = [ucfirst(str_replace('_', ' ', $rec->intentType))];
        if ($rec->sku !== null) {
            $parts[] = $rec->sku;
        }
        if ($rec->quantity !== null) {
            $parts[] = '(' . rtrim(rtrim(number_format($rec->quantity, 2, '.', ''), '0'), '.') . ' units)';
        }

        return implode(' ', $parts);
    }
}
