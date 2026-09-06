<?php

namespace Tests\Feature;

use App\Models\OutboundDispatch;
use App\Platform\Recommendation\DecisionEngine;
use App\Platform\Recommendation\Recommendation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P2.3 — the prescriptive decision layer: recommendations carry expected value,
 * confidence and risk; the score combines them; ranking orders by priority;
 * execute closes the loop through the outbound surface; and realizationRate
 * measures observed against expected.
 */
class PrescriptiveDecisionTest extends TestCase
{
    public function test_score_combines_expected_value_confidence_and_risk(): void
    {
        $engine = app(DecisionEngine::class);

        $score = $engine->score(new Recommendation(
            tenantId: 1, intentType: 'reorder', expectedValue: 10000, confidence: 0.8, risk: 0.25,
        ));

        $this->assertSame(7500.0, $score->riskAdjustedValue); // 10000 × (1 − 0.25)
        $this->assertSame(6000.0, $score->priority);          // 7500 × 0.8
        $this->assertSame('probable', $score->confidenceLabel);
    }

    public function test_rank_orders_by_priority_not_raw_value(): void
    {
        $engine = app(DecisionEngine::class);

        // Bigger EV but lower confidence/higher risk still wins here: 8000 × 0.7 = 5600 > 4500 × 0.9 = 4050.
        $smallSure  = new Recommendation(tenantId: 1, intentType: 'transfer', expectedValue: 5000, confidence: 0.9, risk: 0.1);
        $bigRiskier = new Recommendation(tenantId: 1, intentType: 'reorder', expectedValue: 10000, confidence: 0.7, risk: 0.2);

        $ranked = $engine->rank([$smallSure, $bigRiskier]);

        $this->assertSame('reorder', $ranked->first()['recommendation']->intentType);
    }

    public function test_execute_closes_the_loop_via_the_outbound_surface(): void
    {
        Http::fake();
        $tenant = $this->createTenant();
        $engine = app(DecisionEngine::class);

        $dispatch = $engine->execute(new Recommendation(
            tenantId: $tenant->id, intentType: 'reorder', sku: 'SKU-1', quantity: 100,
            expectedValue: 5000, confidence: 0.8, risk: 0.1, source: 'rec:1',
        ));

        $this->assertInstanceOf(OutboundDispatch::class, $dispatch);
        $this->assertSame('reorder', $dispatch->intent_type);
        $this->assertContains($dispatch->status, [OutboundDispatch::STATUS_SENT, OutboundDispatch::STATUS_ACKNOWLEDGED]);
        // metadata carries the prescriptive triple into the emitted intent.
        $this->assertSame(0.8, $dispatch->request_payload['metadata']['confidence']);
    }

    public function test_realization_rate_measures_observed_against_expected(): void
    {
        $engine = app(DecisionEngine::class);
        $rec = new Recommendation(tenantId: 1, intentType: 'reorder', expectedValue: 10000);

        $this->assertSame(0.75, $engine->realizationRate($rec, 7500));
        $this->assertNull($engine->realizationRate(new Recommendation(tenantId: 1, intentType: 'reorder'), 100));
    }
}
