<?php

namespace App\Platform\Policy;

use App\Platform\Extensibility\Expression\Evaluator;
use App\Platform\Integration\ActionIntent;

/**
 * P4.1 — evaluate a tenant's guardrails against an action-intent and return a
 * {@see PolicyDecision}. Reuses the P3.2 safe AST Evaluator, so guardrail
 * conditions are declarative data that can never execute code. This is the gate
 * intended to run BEFORE dispatch (P2.1): allowed → dispatch; requires_approval →
 * route to a human; blocked → never send. Wiring it into the dispatch path is the
 * consumer step; the engine itself is app-neutral.
 */
class PolicyEngine
{
    public function __construct(
        private readonly PolicyRegistry $registry,
        private readonly Evaluator $evaluator,
    ) {
    }

    /**
     * Evaluate every active guardrail against a variable bag.
     *
     * @param  array<string,mixed>  $vars
     */
    public function evaluate(int $tenantId, array $vars): PolicyDecision
    {
        $violations = [];

        foreach ($this->registry->active($tenantId) as $rule) {
            if ($this->evaluator->evaluate($rule->condition, $vars) === true) {
                $violations[] = [
                    'key'    => $rule->key,
                    'label'  => $rule->label,
                    'effect' => $rule->effect,
                ];
            }
        }

        return new PolicyDecision($violations);
    }

    /**
     * Check an action-intent: builds the variable bag from the intent's fields and
     * metadata (so a guardrail can reference intent_type, quantity, expected_value,
     * confidence, risk, objective, …) and evaluates the tenant's guardrails.
     */
    public function decideForIntent(ActionIntent $intent): PolicyDecision
    {
        $vars = array_merge([
            'intent_type'    => $intent->intentType,
            'sku'            => $intent->sku,
            'store_id'       => $intent->storeId,
            'quantity'       => $intent->quantity,
            'expected_value' => $intent->expectedValue,
            'objective'      => $intent->objective,
        ], $intent->metadata);

        return $this->evaluate($intent->tenantId, $vars);
    }
}
