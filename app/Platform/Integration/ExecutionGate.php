<?php

namespace App\Platform\Integration;

use App\Models\AutonomyPolicy;
use App\Models\Tenant;
use App\Platform\Orchestration\AutonomyRegistry;
use App\Platform\Policy\PolicyEngine;

/**
 * WP7.4 (audit L14, product principle "recommend, don't act") — nothing
 * leaves Autnyx for a tenant's system of record unless all three hold:
 *
 *   1. the tenant opted in explicitly (settings.autonomy.execution_opt_in,
 *      set in Ops — off for every tenant by default);
 *   2. its autonomy level for the intent is above "advise" (AutonomyRegistry;
 *      no policy = advise);
 *   3. the guardrails do not block it (PolicyEngine).
 *
 * Enforced inside OutboundDispatcher, the one path every executor uses
 * (DecisionEngine::execute, outbound:dispatch, OrchestrationPipeline), so no
 * caller can skip it. A refused intent is still recorded, as "held".
 */
class ExecutionGate
{
    public function __construct(
        private readonly AutonomyRegistry $autonomy,
        private readonly PolicyEngine $policy,
    ) {
    }

    /** @return array{allowed: bool, reason: ?string} */
    public function check(ActionIntent $intent): array
    {
        $tenant = Tenant::find($intent->tenantId);
        if (! $tenant || ! $tenant->executionOptedIn()) {
            return ['allowed' => false, 'reason' => 'The tenant has not opted in to sending actions to its systems (recommend-only).'];
        }

        $level = $this->autonomy->resolve($intent->tenantId, $intent->intentType)['level'];
        if ($level === AutonomyPolicy::LEVEL_ADVISE) {
            return ['allowed' => false, 'reason' => "Autonomy for '{$intent->intentType}' is advise-only."];
        }

        $decision = $this->policy->decideForIntent($intent);
        if ($decision->blocked()) {
            return ['allowed' => false, 'reason' => 'Blocked by guardrail: ' . implode('; ', $decision->blockingReasons())];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
