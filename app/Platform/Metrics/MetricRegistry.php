<?php

namespace App\Platform\Metrics;

/**
 * P1.4 — the registry of governed metric definitions. Bound as a singleton;
 * apps register their metrics into it (typically from a service provider boot),
 * and {@see MetricService} resolves values through it. This is the single source
 * of truth for what a metric means and how it is computed.
 */
class MetricRegistry
{
    /** @var array<string,MetricDefinition> */
    private array $metrics = [];

    public function register(MetricDefinition $definition): void
    {
        $this->metrics[$definition->key] = $definition;
    }

    public function get(string $key): ?MetricDefinition
    {
        return $this->metrics[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->metrics[$key]);
    }

    /** @return array<string,MetricDefinition> */
    public function all(): array
    {
        return $this->metrics;
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys($this->metrics);
    }
}
