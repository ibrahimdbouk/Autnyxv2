<?php

namespace App\Platform\Recommendation;

use App\Models\OutboundDispatch;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\OutboundDispatcher;
use Illuminate\Support\Collection;

/**
 * P2.3 — the prescriptive decision layer. Scores recommendations by
 * expected-value × confidence × (1 − risk), ranks a worklist, and closes the
 * loop by executing a recommendation through the P2.1 outbound surface
 * (recommendation → action-intent → dispatch). realizationRate() then compares a
 * later observed outcome against what was expected — the "measured" end of the loop.
 */
class DecisionEngine
{
    public function __construct(private readonly OutboundDispatcher $dispatcher)
    {
    }

    public function score(Recommendation $recommendation): RecommendationScore
    {
        $confidence = $this->clamp($recommendation->confidence);
        $risk       = $this->clamp($recommendation->risk);

        $riskAdjustedValue = $recommendation->expectedValue * (1.0 - $risk);
        $priority          = $riskAdjustedValue * $confidence;

        return new RecommendationScore(
            $recommendation->expectedValue,
            $confidence,
            $risk,
            round($riskAdjustedValue, 2),
            round($priority, 2),
            $this->confidenceLabel($confidence),
        );
    }

    /**
     * Rank recommendations, highest priority first.
     *
     * @param  iterable<Recommendation>  $recommendations
     * @return Collection<int,array{recommendation:Recommendation,score:RecommendationScore}>
     */
    public function rank(iterable $recommendations): Collection
    {
        return collect($recommendations)
            ->map(fn (Recommendation $r) => ['recommendation' => $r, 'score' => $this->score($r)])
            ->sortByDesc(fn (array $row) => $row['score']->priority)
            ->values();
    }

    /**
     * Close the loop: execute a recommendation by emitting it to the tenant's
     * replenishment system of record via the P2.1 outbound surface. Returns the
     * dispatch round-trip record.
     */
    public function execute(Recommendation $recommendation): OutboundDispatch
    {
        $intent = new ActionIntent(
            tenantId: $recommendation->tenantId,
            intentType: $recommendation->intentType,
            sku: $recommendation->sku,
            storeId: $recommendation->storeId,
            quantity: $recommendation->quantity,
            targetDate: $recommendation->targetDate,
            rationale: $recommendation->rationale,
            expectedValue: $recommendation->expectedValue,
            objective: $recommendation->objective,
            source: $recommendation->source,
            metadata: [
                'confidence' => $recommendation->confidence,
                'risk'       => $recommendation->risk,
            ],
        );

        return $this->dispatcher->dispatch($intent);
    }

    /** Observed value as a fraction of what was expected (null when no expectation). */
    public function realizationRate(Recommendation $recommendation, float $observedValue): ?float
    {
        return $recommendation->expectedValue > 0
            ? round($observedValue / $recommendation->expectedValue, 3)
            : null;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function confidenceLabel(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.85 => 'established',
            $confidence >= 0.60 => 'probable',
            $confidence >= 0.35 => 'suspected',
            default             => 'unknown',
        };
    }
}
