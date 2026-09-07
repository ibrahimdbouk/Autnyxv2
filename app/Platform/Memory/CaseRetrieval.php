<?php

namespace App\Platform\Memory;

use App\Models\DecisionCase;
use Illuminate\Support\Collection;

/**
 * P4.3 — read side of decision memory: retrieve past situations similar to a new
 * one, and summarise how their recommendations turned out. This is what lets the
 * DecisionEngine (P2.3) eventually say "in similar situations, a reorder recovered
 * 78% of expected value" — evidence from experience, not just a model score.
 */
class CaseRetrieval
{
    private const CANDIDATE_LIMIT = 500;

    /**
     * Cases most similar to a situation, highest similarity first.
     *
     * @param  array<string,mixed>  $situation
     * @param  array{intent_type?:string,objective?:string,sku?:string}  $filters
     * @return Collection<int,array{case:DecisionCase,similarity:float}>
     */
    public function similar(int $tenantId, array $situation, array $filters = [], int $limit = 10): Collection
    {
        $candidates = DecisionCase::query()
            ->where('tenant_id', $tenantId)
            ->when($filters['intent_type'] ?? null, fn ($q, $v) => $q->where('intent_type', $v))
            ->when($filters['objective'] ?? null, fn ($q, $v) => $q->where('objective', $v))
            ->when($filters['sku'] ?? null, fn ($q, $v) => $q->where('sku', $v))
            ->latest('occurred_at')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        return $candidates
            ->map(fn (DecisionCase $c) => [
                'case'       => $c,
                'similarity' => Similarity::cosine($situation, $c->situation ?? []),
            ])
            ->sortByDesc('similarity')
            ->take($limit)
            ->values();
    }

    /**
     * How recommendations turned out in situations like this one. Considers only
     * RESOLVED similar cases (an outcome was actually measured).
     *
     * @param  array<string,mixed>  $situation
     * @return array{n:int,success_rate:?float,avg_realization:?float,avg_realized_value:?float}
     */
    public function successRate(int $tenantId, array $situation, ?string $intentType = null, int $limit = 50): array
    {
        $resolved = $this->similar(
            $tenantId,
            $situation,
            $intentType !== null ? ['intent_type' => $intentType] : [],
            $limit,
        )->filter(fn (array $r) => $r['case']->isResolved());

        $n = $resolved->count();
        if ($n === 0) {
            return ['n' => 0, 'success_rate' => null, 'avg_realization' => null, 'avg_realized_value' => null];
        }

        $successes = $resolved->filter(fn (array $r) => $r['case']->outcome_status === DecisionCase::OUTCOME_SUCCESS)->count();
        $rates = $resolved->map(fn (array $r) => $r['case']->realization_rate)->filter(fn ($v) => $v !== null);
        $values = $resolved->map(fn (array $r) => $r['case']->realized_value)->filter(fn ($v) => $v !== null);

        return [
            'n'                  => $n,
            'success_rate'       => round($successes / $n, 3),
            'avg_realization'    => $rates->isNotEmpty() ? round((float) $rates->avg(), 3) : null,
            'avg_realized_value' => $values->isNotEmpty() ? round((float) $values->avg(), 2) : null,
        ];
    }
}
