<?php

namespace App\Platform\Extensibility;

use App\Models\CustomRuleDefinition;
use App\Platform\Extensibility\Expression\Evaluator;
use Illuminate\Support\Collection;

/**
 * P3.2 — evaluate a tenant's custom rules against a variable bag and return the
 * ones that FIRE. The platform owns storage + safe evaluation; feeding fired
 * rules into the Root-Cause detection engine is a later consumer step (that engine
 * is deliberately not touched here). A tenant adds a rule as a row and it takes
 * effect on the next evaluation — no deploy, no migration.
 */
class RuleEvaluator
{
    public function __construct(private readonly Evaluator $evaluator)
    {
    }

    /**
     * @param  array<string,mixed>  $vars
     * @return Collection<int,array{key:string,label:string,severity:string,objective:?string}>
     */
    public function fired(int $tenantId, array $vars = []): Collection
    {
        return CustomRuleDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->get()
            ->filter(fn (CustomRuleDefinition $rule) => $this->matches($rule, $vars))
            ->map(fn (CustomRuleDefinition $rule) => [
                'key'       => $rule->key,
                'label'     => $rule->label,
                'severity'  => $rule->severity,
                'objective' => $rule->objective,
            ])
            ->values();
    }

    /**
     * @param  array<string,mixed>  $vars
     */
    public function matches(CustomRuleDefinition $rule, array $vars = []): bool
    {
        return $this->evaluator->evaluate($rule->condition, $vars) === true;
    }
}
