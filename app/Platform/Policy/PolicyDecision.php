<?php

namespace App\Platform\Policy;

use App\Models\PolicyRule;
use Illuminate\Support\Collection;

/**
 * P4.1 — the outcome of evaluating a tenant's guardrails against one action-intent.
 * Derives the verdict from the violated policies: any BLOCK → not allowed; else any
 * REQUIRE_APPROVAL → allowed but needs a human; WARN → allowed with warnings.
 */
class PolicyDecision
{
    /**
     * @param  array<int,array{key:string,label:string,effect:string}>  $violations
     */
    public function __construct(public readonly array $violations = [])
    {
    }

    private function withEffect(string $effect): Collection
    {
        return collect($this->violations)->where('effect', $effect)->values();
    }

    public function blocked(): bool
    {
        return $this->withEffect(PolicyRule::EFFECT_BLOCK)->isNotEmpty();
    }

    public function requiresApproval(): bool
    {
        return ! $this->blocked() && $this->withEffect(PolicyRule::EFFECT_REQUIRE_APPROVAL)->isNotEmpty();
    }

    /** May the action proceed automatically (no block, no approval needed)? */
    public function allowed(): bool
    {
        return ! $this->blocked() && ! $this->requiresApproval();
    }

    /** @return array<int,string> */
    public function warnings(): array
    {
        return $this->withEffect(PolicyRule::EFFECT_WARN)->pluck('label')->all();
    }

    /** @return array<int,string> */
    public function blockingReasons(): array
    {
        return $this->withEffect(PolicyRule::EFFECT_BLOCK)->pluck('label')->all();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'allowed'           => $this->allowed(),
            'blocked'           => $this->blocked(),
            'requires_approval' => $this->requiresApproval(),
            'violations'        => $this->violations,
            'warnings'          => $this->warnings(),
        ];
    }
}
