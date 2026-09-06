<?php

namespace App\Platform\Extensibility;

use App\Models\CustomMetricDefinition;
use App\Platform\Extensibility\Expression\Evaluator;

/**
 * P3.2 — compute a tenant's custom KPI. The caller supplies the variable bag
 * (base metric/feature values for the entity in context — typically pulled from
 * the P1.4 metric layer / P1.5 feature store), and the stored expression is
 * evaluated safely against it. This extends the metric layer with tenant-authored
 * definitions without a code change or a migration.
 */
class CustomMetricService
{
    public function __construct(private readonly Evaluator $evaluator)
    {
    }

    /**
     * Compute one custom metric by key. Returns null if the metric is unknown /
     * inactive, or the expression yields no value.
     *
     * @param  array<string,mixed>  $vars
     */
    public function compute(int $tenantId, string $key, array $vars = []): int|float|bool|null
    {
        $definition = CustomMetricDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->where('active', true)
            ->first();

        if (! $definition) {
            return null;
        }

        return $this->evaluator->evaluate($definition->expression, $vars);
    }

    /**
     * Compute every active custom metric for a tenant against one variable bag.
     *
     * @param  array<string,mixed>  $vars
     * @return array<string,int|float|bool|null>
     */
    public function computeAll(int $tenantId, array $vars = []): array
    {
        return CustomMetricDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->get()
            ->mapWithKeys(fn (CustomMetricDefinition $d) => [
                $d->key => $this->evaluator->evaluate($d->expression, $vars),
            ])
            ->all();
    }
}
