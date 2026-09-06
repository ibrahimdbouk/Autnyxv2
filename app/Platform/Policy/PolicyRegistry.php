<?php

namespace App\Platform\Policy;

use App\Models\PolicyRule;
use Illuminate\Support\Collection;

/**
 * P4.1 — define and look up a tenant's guardrails. A guardrail is data (a row),
 * so declaring one is an upsert, not a migration.
 */
class PolicyRegistry
{
    /**
     * @param  array<string,mixed>  $condition  safe boolean AST; true = violated
     */
    public function define(
        int $tenantId,
        string $key,
        string $label,
        array $condition,
        string $effect = PolicyRule::EFFECT_BLOCK,
    ): PolicyRule {
        return PolicyRule::updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['label' => $label, 'condition' => $condition, 'effect' => $effect, 'active' => true],
        );
    }

    /** @return Collection<int,PolicyRule> */
    public function active(int $tenantId): Collection
    {
        return PolicyRule::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('key')
            ->get();
    }

    /** @return Collection<int,PolicyRule> */
    public function all(int $tenantId): Collection
    {
        return PolicyRule::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('key')
            ->get();
    }
}
