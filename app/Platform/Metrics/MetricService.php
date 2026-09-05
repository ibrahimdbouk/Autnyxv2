<?php

namespace App\Platform\Metrics;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * P1.4 — the read API for governed metrics. Callers ask for a metric by key and
 * get back a {@see MetricValue} (number + unit + definition version), computed
 * from the one registered formula. No caller recomputes a KPI itself.
 */
class MetricService
{
    public function __construct(private readonly MetricRegistry $registry)
    {
    }

    /**
     * Compute one metric for a tenant.
     *
     * @param  array<string,mixed>  $options  optional scope/date filters passed to the resolver
     */
    public function value(string $key, int $tenantId, array $options = []): MetricValue
    {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown metric [{$key}].");
        }

        $raw = ($definition->resolver)($tenantId, $options);

        return new MetricValue($key, (float) $raw, $definition->unit, $definition->version, now());
    }

    /**
     * Compute every registered metric for a tenant.
     *
     * @param  array<string,mixed>  $options
     * @return Collection<string,MetricValue>
     */
    public function all(int $tenantId, array $options = []): Collection
    {
        return collect($this->registry->all())
            ->map(fn (MetricDefinition $definition) => $this->value($definition->key, $tenantId, $options));
    }
}
