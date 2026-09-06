<?php

namespace App\Platform\Objectives;

use Closure;
use Illuminate\Support\Collection;

/**
 * P2.2 — scores and ranks outputs for an objective. score = objective-weight(rule
 * type) × impact (money, where known). The same list of anomalies/recommendations
 * therefore re-orders when the objective changes — a stockout tops the list under
 * "availability", an overstock tops it under "working capital".
 */
class ObjectiveScorer
{
    public function __construct(private readonly ObjectiveRegistry $registry)
    {
    }

    public function score(string $objective, string $ruleType, float $impact = 1.0): float
    {
        return $this->registry->weight($objective, $ruleType) * max($impact, 0.0);
    }

    /**
     * Rank items for an objective, highest score first. Each result row is
     * ['item' => original, 'score' => float].
     *
     * @param  iterable<mixed>  $items
     * @param  Closure(mixed): string  $ruleTypeOf   extract a rule type from an item
     * @param  Closure(mixed): float|null  $impactOf  extract a money impact (optional)
     * @return Collection<int,array{item:mixed,score:float}>
     */
    public function rank(string $objective, iterable $items, Closure $ruleTypeOf, ?Closure $impactOf = null): Collection
    {
        return collect($items)
            ->map(function ($item) use ($objective, $ruleTypeOf, $impactOf) {
                $impact = $impactOf ? (float) $impactOf($item) : 1.0;

                return [
                    'item'  => $item,
                    // fall back to unit impact so weight still orders when money is unknown/zero
                    'score' => $this->score($objective, (string) $ruleTypeOf($item), $impact ?: 1.0),
                ];
            })
            ->sortByDesc('score')
            ->values();
    }
}
