<?php

namespace App\Platform\Extensibility;

use App\Models\CustomAttributeDefinition;
use App\Models\CustomMetricDefinition;
use App\Models\CustomRuleDefinition;
use Illuminate\Support\Collection;

/**
 * P3.2 — introspection over a tenant's stored extensions (custom KPIs, rules,
 * dimensions). Everything a tenant has added without a migration is enumerable
 * here — for admin surfaces, packaging, and audit. The extension point is data,
 * so the registry is just governed queries over it.
 */
class ExtensibilityRegistry
{
    /** @return Collection<int,CustomMetricDefinition> */
    public function metrics(int $tenantId, bool $activeOnly = true): Collection
    {
        return CustomMetricDefinition::query()
            ->where('tenant_id', $tenantId)
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderBy('key')
            ->get();
    }

    /** @return Collection<int,CustomRuleDefinition> */
    public function rules(int $tenantId, bool $activeOnly = true): Collection
    {
        return CustomRuleDefinition::query()
            ->where('tenant_id', $tenantId)
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderBy('key')
            ->get();
    }

    /** @return Collection<int,CustomAttributeDefinition> */
    public function dimensions(int $tenantId, bool $activeOnly = true): Collection
    {
        return CustomAttributeDefinition::query()
            ->where('tenant_id', $tenantId)
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderBy('entity_type')
            ->orderBy('key')
            ->get();
    }

    /** @return array{metrics:int,rules:int,dimensions:int} */
    public function counts(int $tenantId): array
    {
        return [
            'metrics'    => CustomMetricDefinition::where('tenant_id', $tenantId)->where('active', true)->count(),
            'rules'      => CustomRuleDefinition::where('tenant_id', $tenantId)->where('active', true)->count(),
            'dimensions' => CustomAttributeDefinition::where('tenant_id', $tenantId)->where('active', true)->count(),
        ];
    }
}
