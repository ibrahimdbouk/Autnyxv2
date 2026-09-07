<?php

namespace App\Platform\Orchestration;

use App\Models\AutonomyPolicy;

/**
 * P4.8 — define and resolve a tenant's autonomy level. Resolution is
 * most-specific-wins: a policy for the exact intent_type beats the tenant default
 * ('*'); with neither configured, the platform falls back to the SAFEST level
 * (advise) — autonomy is never on by default.
 */
class AutonomyRegistry
{
    public function define(
        int $tenantId,
        string $level,
        string $intentType = AutonomyPolicy::DEFAULT_KEY,
        float $minConfidence = AutonomyPolicy::DEFAULT_MIN_CONFIDENCE,
    ): AutonomyPolicy {
        return AutonomyPolicy::updateOrCreate(
            ['tenant_id' => $tenantId, 'intent_type' => $intentType],
            ['level' => $level, 'min_confidence' => $minConfidence, 'active' => true],
        );
    }

    /**
     * @return array{level:string,min_confidence:float}
     */
    public function resolve(int $tenantId, string $intentType): array
    {
        $rows = AutonomyPolicy::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->whereIn('intent_type', [$intentType, AutonomyPolicy::DEFAULT_KEY])
            ->get();

        $chosen = $rows->firstWhere('intent_type', $intentType)
            ?? $rows->firstWhere('intent_type', AutonomyPolicy::DEFAULT_KEY);

        return $chosen
            ? ['level' => $chosen->level, 'min_confidence' => (float) $chosen->min_confidence]
            : ['level' => AutonomyPolicy::DEFAULT_LEVEL, 'min_confidence' => AutonomyPolicy::DEFAULT_MIN_CONFIDENCE];
    }
}
