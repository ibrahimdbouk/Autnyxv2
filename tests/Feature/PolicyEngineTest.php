<?php

namespace Tests\Feature;

use App\Models\PolicyRule;
use App\Platform\Integration\ActionIntent;
use App\Platform\Policy\PolicyEngine;
use App\Platform\Policy\PolicyRegistry;
use Tests\TestCase;

/**
 * P4.1 — guardrails evaluated against an action-intent before dispatch.
 */
class PolicyEngineTest extends TestCase
{
    private function gt(string $var, float $limit): array
    {
        return ['type' => 'op', 'op' => '>', 'args' => [['type' => 'var', 'name' => $var], ['type' => 'const', 'value' => $limit]]];
    }

    private function lt(string $var, float $limit): array
    {
        return ['type' => 'op', 'op' => '<', 'args' => [['type' => 'var', 'name' => $var], ['type' => 'const', 'value' => $limit]]];
    }

    public function test_a_block_guardrail_blocks_a_matching_intent(): void
    {
        $tenant = $this->createTenant();
        // Block any movement over 1000 units.
        app(PolicyRegistry::class)->define($tenant->id, 'max_move', 'No move over 1000 units',
            $this->gt('quantity', 1000), PolicyRule::EFFECT_BLOCK);

        $engine = app(PolicyEngine::class);

        $blocked = $engine->evaluate($tenant->id, ['quantity' => 2000]);
        $this->assertTrue($blocked->blocked());
        $this->assertFalse($blocked->allowed());
        $this->assertContains('No move over 1000 units', $blocked->blockingReasons());

        $ok = $engine->evaluate($tenant->id, ['quantity' => 500]);
        $this->assertTrue($ok->allowed());
    }

    public function test_min_confidence_guardrail_via_the_shared_ast(): void
    {
        $tenant = $this->createTenant();
        // Require ≥ 0.8 confidence to auto-execute: violated when confidence < 0.8.
        app(PolicyRegistry::class)->define($tenant->id, 'min_conf', 'Confidence below 0.8',
            $this->lt('confidence', 0.8), PolicyRule::EFFECT_REQUIRE_APPROVAL);

        $engine = app(PolicyEngine::class);

        $low = $engine->evaluate($tenant->id, ['confidence' => 0.6]);
        $this->assertTrue($low->requiresApproval());
        $this->assertFalse($low->allowed());
        $this->assertFalse($low->blocked());

        $high = $engine->evaluate($tenant->id, ['confidence' => 0.9]);
        $this->assertTrue($high->allowed());
    }

    public function test_decide_for_intent_reads_fields_and_metadata(): void
    {
        $tenant = $this->createTenant();
        app(PolicyRegistry::class)->define($tenant->id, 'min_conf', 'Confidence below 0.8',
            $this->lt('confidence', 0.8), PolicyRule::EFFECT_BLOCK);

        $intent = new ActionIntent(
            tenantId: $tenant->id,
            intentType: 'reorder',
            quantity: 100,
            expectedValue: 5000,
            metadata: ['confidence' => 0.5, 'risk' => 0.2], // confidence lives in metadata (as P2.3 emits it)
        );

        $decision = app(PolicyEngine::class)->decideForIntent($intent);
        $this->assertTrue($decision->blocked());
    }

    public function test_warn_allows_but_surfaces_a_warning(): void
    {
        $tenant = $this->createTenant();
        app(PolicyRegistry::class)->define($tenant->id, 'big_value', 'High-value action',
            $this->gt('expected_value', 100000), PolicyRule::EFFECT_WARN);

        $decision = app(PolicyEngine::class)->evaluate($tenant->id, ['expected_value' => 250000]);
        $this->assertTrue($decision->allowed());
        $this->assertContains('High-value action', $decision->warnings());
    }

    public function test_guardrails_are_tenant_scoped(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        app(PolicyRegistry::class)->define($a->id, 'max_move', 'cap', $this->gt('quantity', 10), PolicyRule::EFFECT_BLOCK);

        $this->assertTrue(app(PolicyEngine::class)->evaluate($a->id, ['quantity' => 50])->blocked());
        $this->assertTrue(app(PolicyEngine::class)->evaluate($b->id, ['quantity' => 50])->allowed());
    }
}
