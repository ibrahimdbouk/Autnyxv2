<?php

namespace App\Platform\Objectives;

/**
 * P2.2 — the registry of objectives and their per-rule weights. Bound as a
 * singleton; apps register their objectives + the domain weight map (which rule
 * types matter for each objective) into it, the same way the metric registry
 * works. This is the single source of truth for "what does this objective care
 * about, and how much."
 */
class ObjectiveRegistry
{
    /** @var array<string,Objective> */
    private array $objectives = [];

    /** @var array<string,array<string,float>> objective key => (rule type => weight) */
    private array $weights = [];

    /**
     * @param  array<string,float>  $weights  rule type => weight for this objective
     */
    public function register(Objective $objective, array $weights = []): void
    {
        $this->objectives[$objective->key] = $objective;
        $this->weights[$objective->key]    = $weights;
    }

    public function get(string $key): ?Objective
    {
        return $this->objectives[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->objectives[$key]);
    }

    /** @return array<string,Objective> */
    public function all(): array
    {
        return $this->objectives;
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys($this->objectives);
    }

    /** The weight this objective assigns a rule type (its default when unlisted). */
    public function weight(string $objective, string $ruleType): float
    {
        $definition = $this->objectives[$objective] ?? null;
        if ($definition === null) {
            return 1.0;
        }

        return $this->weights[$objective][$ruleType] ?? $definition->defaultWeight;
    }
}
