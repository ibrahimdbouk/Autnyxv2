<?php

namespace Tests\Feature;

use App\Models\ApprovalQueueItem;
use App\Models\AutonomyPolicy;
use App\Models\DecisionCase;
use App\Platform\Memory\CaseRecorder;
use App\Platform\Orchestration\AutonomyRegistry;
use App\Platform\Orchestration\OrchestrationOutcome;
use App\Platform\Orchestration\OrchestrationPipeline;
use App\Models\PolicyRule;
use App\Platform\Policy\PolicyRegistry;
use App\Platform\Recommendation\Recommendation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P4.8 — the orchestration engine routes a scored recommendation by the tenant's
 * autonomy level, the guardrails, and the (data- and memory-adjusted) confidence.
 */
class OrchestrationTest extends TestCase
{
    private function rec(int $tenantId, float $confidence = 0.9, ?float $quantity = 100): Recommendation
    {
        return new Recommendation(
            tenantId: $tenantId, intentType: 'reorder', sku: 'SKU-1',
            quantity: $quantity, expectedValue: 5000, confidence: $confidence, risk: 0.1, source: 'sig:1',
        );
    }

    public function test_auto_executes_when_confident_and_safe(): void
    {
        Http::fake();
        $tenant = $this->createTenant();
        app(AutonomyRegistry::class)->define($tenant->id, AutonomyPolicy::LEVEL_AUTO, 'reorder', 0.8);

        $outcome = app(OrchestrationPipeline::class)->orchestrate($this->rec($tenant->id, 0.9));

        $this->assertSame(OrchestrationOutcome::MODE_EXECUTED, $outcome->mode);
        $this->assertNotNull($outcome->dispatchId);
        // the decision arc was remembered as adopted
        $this->assertSame(DecisionCase::DECISION_ADOPTED, DecisionCase::find($outcome->caseId)->decision);
    }

    public function test_auto_falls_back_to_approval_when_confidence_below_threshold(): void
    {
        $tenant = $this->createTenant();
        app(AutonomyRegistry::class)->define($tenant->id, AutonomyPolicy::LEVEL_AUTO, 'reorder', 0.8);

        $outcome = app(OrchestrationPipeline::class)->orchestrate($this->rec($tenant->id, 0.6));

        $this->assertSame(OrchestrationOutcome::MODE_QUEUED, $outcome->mode);
        $this->assertNotNull($outcome->approvalId);
        $this->assertSame(1, ApprovalQueueItem::where('tenant_id', $tenant->id)->count());
        $this->assertTrue(collect($outcome->reasons)->contains(fn ($r) => str_contains($r, 'below threshold')));
    }

    public function test_a_guardrail_blocks_regardless_of_autonomy(): void
    {
        $tenant = $this->createTenant();
        app(AutonomyRegistry::class)->define($tenant->id, AutonomyPolicy::LEVEL_AUTO, 'reorder', 0.5);
        app(PolicyRegistry::class)->define($tenant->id, 'cap', 'No move over 1000',
            ['type' => 'op', 'op' => '>', 'args' => [['type' => 'var', 'name' => 'quantity'], ['type' => 'const', 'value' => 1000]]],
            PolicyRule::EFFECT_BLOCK);

        $outcome = app(OrchestrationPipeline::class)->orchestrate($this->rec($tenant->id, 0.99, quantity: 2000));

        $this->assertSame(OrchestrationOutcome::MODE_BLOCKED, $outcome->mode);
        $this->assertNull($outcome->dispatchId);
        $this->assertSame(DecisionCase::DECISION_REJECTED, DecisionCase::find($outcome->caseId)->decision);
    }

    public function test_advise_level_only_advises(): void
    {
        $tenant = $this->createTenant();
        app(AutonomyRegistry::class)->define($tenant->id, AutonomyPolicy::LEVEL_ADVISE, 'reorder');

        $outcome = app(OrchestrationPipeline::class)->orchestrate($this->rec($tenant->id, 0.99));

        $this->assertSame(OrchestrationOutcome::MODE_ADVISED, $outcome->mode);
        $this->assertNull($outcome->dispatchId);
        $this->assertNull($outcome->approvalId);
        $this->assertSame(0, ApprovalQueueItem::where('tenant_id', $tenant->id)->count());
    }

    public function test_default_is_advise_when_no_policy_configured(): void
    {
        $tenant = $this->createTenant();
        $outcome = app(OrchestrationPipeline::class)->orchestrate($this->rec($tenant->id, 0.99));
        $this->assertSame(OrchestrationOutcome::MODE_ADVISED, $outcome->mode);
        $this->assertSame('advise', $outcome->level);
    }

    public function test_memory_pulls_confidence_down_and_forces_approval(): void
    {
        $tenant = $this->createTenant();
        app(AutonomyRegistry::class)->define($tenant->id, AutonomyPolicy::LEVEL_AUTO, 'reorder', 0.8);

        // Two similar past reorders that FAILED — memory success rate 0.0.
        $recorder = app(CaseRecorder::class);
        foreach ([1, 2] as $_) {
            $c = $recorder->record($this->rec($tenant->id, 0.9), situation: ['drop_pct' => 40]);
            $recorder->recordOutcome($c, DecisionCase::OUTCOME_FAILURE, 0);
        }

        // Base confidence is high (0.9) but memory blends it down: (0.9 + 0.0)/2 = 0.45 < 0.8.
        $outcome = app(OrchestrationPipeline::class)->orchestrate(
            $this->rec($tenant->id, 0.9),
            situation: ['drop_pct' => 41],
        );

        $this->assertSame(OrchestrationOutcome::MODE_QUEUED, $outcome->mode);
        $this->assertSame(0.45, $outcome->effectiveConfidence);
        $this->assertTrue(collect($outcome->reasons)->contains(fn ($r) => str_contains($r, 'memory:')));
    }
}
