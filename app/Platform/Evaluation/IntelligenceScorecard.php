<?php

namespace App\Platform\Evaluation;

use App\Models\DecisionCase;
use Illuminate\Support\Collection;

/**
 * P4.4 — compute an intelligence quality scorecard from the P4.3 decision-memory
 * case base. For the tenant overall and per intent_type / objective it reports:
 * how many decisions were adopted, how often they succeeded, how much value they
 * realized, and — the key trust metric — how well-calibrated the AI is
 * (avg predicted confidence vs actual success rate; a positive gap = overconfident).
 * Pure read over decision_cases; DB-agnostic (aggregates in memory).
 */
class IntelligenceScorecard
{
    private const CASE_LIMIT = 5000;

    /**
     * @return array{overall:array<string,mixed>,by_intent_type:array<int,array<string,mixed>>,by_objective:array<int,array<string,mixed>>}
     */
    public function compute(int $tenantId, ?string $period = null): array
    {
        $cases = DecisionCase::query()
            ->where('tenant_id', $tenantId)
            ->latest('occurred_at')
            ->limit(self::CASE_LIMIT)
            ->get();

        return [
            'overall'        => $this->metricsFor($cases) + ['dim_key' => null],
            'by_intent_type' => $this->grouped($cases, 'intent_type'),
            'by_objective'   => $this->grouped($cases, 'objective'),
        ];
    }

    /**
     * @param  Collection<int,DecisionCase>  $cases
     * @return array<int,array<string,mixed>>
     */
    private function grouped(Collection $cases, string $field): array
    {
        return $cases
            ->groupBy(fn (DecisionCase $c) => $c->{$field} ?? '(none)')
            ->map(fn (Collection $group, $key) => $this->metricsFor($group) + ['dim_key' => (string) $key])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,DecisionCase>  $cases
     * @return array{n:int,resolved:int,adoption_rate:?float,success_rate:?float,avg_realization:?float,avg_confidence:?float,calibration_gap:?float}
     */
    public function metricsFor(Collection $cases): array
    {
        $n = $cases->count();
        $adopted = $cases->where('decision', DecisionCase::DECISION_ADOPTED)->count();
        $resolved = $cases->filter(fn (DecisionCase $c) => $c->isResolved());
        $rc = $resolved->count();
        $successes = $resolved->where('outcome_status', DecisionCase::OUTCOME_SUCCESS)->count();

        $confidences = $cases->map(fn (DecisionCase $c) => $c->confidence)->filter(fn ($v) => $v !== null);
        $realizations = $resolved->map(fn (DecisionCase $c) => $c->realization_rate)->filter(fn ($v) => $v !== null);

        $successRate = $rc > 0 ? round($successes / $rc, 3) : null;
        $avgConfidence = $confidences->isNotEmpty() ? round((float) $confidences->avg(), 3) : null;

        return [
            'n'               => $n,
            'resolved'        => $rc,
            'adoption_rate'   => $n > 0 ? round($adopted / $n, 3) : null,
            'success_rate'    => $successRate,
            'avg_realization' => $realizations->isNotEmpty() ? round((float) $realizations->avg(), 3) : null,
            'avg_confidence'  => $avgConfidence,
            'calibration_gap' => ($avgConfidence !== null && $successRate !== null)
                ? round($avgConfidence - $successRate, 3)
                : null,
        ];
    }
}
