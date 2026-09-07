<?php

namespace App\Platform\Objectives;

use Illuminate\Support\Collection;

/**
 * P4.6 — rank candidates against a weighted blend of objectives, not one at a
 * time. blended = Σ (normalised weight_o × impact_o); each objective's weighted
 * contribution is exposed so the tradeoff is legible, and candidates that are
 * Pareto-dominated (beaten on every objective by another) are flagged.
 */
class MultiObjectiveScorer
{
    public function __construct(private readonly WeightingRegistry $weights)
    {
    }

    /**
     * Score one candidate.
     *
     * @param  array<string,float>  $weighting  objective => weight (any scale; normalised here)
     * @param  array<string,float>  $impacts    objective => raw benefit
     */
    public function score(array $weighting, array $impacts, ?string $key = null, bool $dominated = false): MultiObjectiveScore
    {
        $keys = array_keys($impacts);

        // Effective weights over the objectives this candidate has an impact on.
        $w = [];
        foreach ($keys as $k) {
            $w[$k] = (float) ($weighting[$k] ?? 0.0);
        }
        $total = array_sum($w);

        if ($total <= 0.0) {
            // No usable weighting → equal weight across the candidate's objectives.
            $n = max(count($keys), 1);
            foreach ($keys as $k) {
                $w[$k] = 1.0 / $n;
            }
        } else {
            foreach ($keys as $k) {
                $w[$k] = $w[$k] / $total;
            }
        }

        $contributions = [];
        foreach ($keys as $k) {
            $contributions[$k] = round($w[$k] * (float) $impacts[$k], 4);
        }

        return new MultiObjectiveScore(
            key: $key,
            blended: round(array_sum($contributions), 4),
            contributions: $contributions,
            impacts: array_map('floatval', $impacts),
            dominated: $dominated,
        );
    }

    /**
     * Rank candidates (highest blended first), flagging Pareto-dominated ones.
     *
     * @param  array<string,float>  $weighting
     * @param  array<string,array<string,float>>  $candidates  key => (objective => impact)
     * @return Collection<int,MultiObjectiveScore>
     */
    public function rank(array $weighting, array $candidates): Collection
    {
        $objectives = $this->objectiveUnion($candidates);

        return collect($candidates)
            ->map(fn (array $impacts, string $key) => $this->score(
                $weighting,
                $impacts,
                $key,
                $this->isDominated($key, $candidates, $objectives),
            ))
            ->sortByDesc(fn (MultiObjectiveScore $s) => $s->blended)
            ->values();
    }

    /**
     * Rank using the tenant's stored weighting.
     *
     * @param  array<string,array<string,float>>  $candidates
     * @return Collection<int,MultiObjectiveScore>
     */
    public function rankForTenant(int $tenantId, array $candidates): Collection
    {
        return $this->rank($this->weights->weighting($tenantId), $candidates);
    }

    /**
     * The Pareto frontier: keys not dominated by any other candidate.
     *
     * @param  array<string,array<string,float>>  $candidates
     * @return array<int,string>
     */
    public function paretoFrontier(array $candidates): array
    {
        $objectives = $this->objectiveUnion($candidates);

        return array_values(array_filter(
            array_keys($candidates),
            fn (string $key) => ! $this->isDominated($key, $candidates, $objectives),
        ));
    }

    /**
     * @param  array<string,array<string,float>>  $candidates
     * @param  array<int,string>  $objectives
     */
    private function isDominated(string $key, array $candidates, array $objectives): bool
    {
        $me = $candidates[$key];

        foreach ($candidates as $otherKey => $other) {
            if ($otherKey === $key) {
                continue;
            }

            $allGE = true;
            $anyGT = false;
            foreach ($objectives as $o) {
                $a = (float) ($other[$o] ?? 0.0);
                $b = (float) ($me[$o] ?? 0.0);
                if ($a < $b) {
                    $allGE = false;
                    break;
                }
                if ($a > $b) {
                    $anyGT = true;
                }
            }

            if ($allGE && $anyGT) {
                return true; // 'other' beats 'me' on every objective, better on ≥1
            }
        }

        return false;
    }

    /**
     * @param  array<string,array<string,float>>  $candidates
     * @return array<int,string>
     */
    private function objectiveUnion(array $candidates): array
    {
        $keys = [];
        foreach ($candidates as $impacts) {
            $keys = array_merge($keys, array_keys($impacts));
        }

        return array_values(array_unique($keys));
    }
}
