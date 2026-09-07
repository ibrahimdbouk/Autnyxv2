<?php

namespace App\Platform\Objectives;

use App\Models\ObjectiveWeight;

/**
 * P4.6 — define and read a tenant's objective weighting (the optimisation blend).
 * A weight is data, so setting one is an upsert. Normalisation is left to the
 * {@see MultiObjectiveScorer} so weights can be stored in whatever scale is
 * convenient (e.g. 5/3/2) and still blend correctly.
 */
class WeightingRegistry
{
    public function setWeight(int $tenantId, string $objective, float $weight): ObjectiveWeight
    {
        return ObjectiveWeight::updateOrCreate(
            ['tenant_id' => $tenantId, 'objective' => $objective],
            ['weight' => $weight, 'active' => true],
        );
    }

    /**
     * The tenant's active weighting as {objective: weight}. Empty when none set —
     * the scorer then falls back to equal weighting.
     *
     * @return array<string,float>
     */
    public function weighting(int $tenantId): array
    {
        return ObjectiveWeight::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->pluck('weight', 'objective')
            ->map(fn ($w) => (float) $w)
            ->all();
    }
}
