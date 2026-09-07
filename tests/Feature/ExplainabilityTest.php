<?php

namespace Tests\Feature;

use App\Platform\Explainability\Explanation;
use App\Platform\Explainability\ExplanationBuilder;
use App\Platform\Orchestration\OrchestrationOutcome;
use App\Platform\Recommendation\Recommendation;
use Tests\TestCase;

/**
 * P4.5 — the universal explanation contract: one shape, assembled from the
 * platform's own pieces, with a consistent confidence label everywhere.
 */
class ExplainabilityTest extends TestCase
{
    public function test_confidence_labels_match_the_platform_thresholds(): void
    {
        $this->assertSame('established', Explanation::labelFor(0.90));
        $this->assertSame('probable', Explanation::labelFor(0.70));
        $this->assertSame('suspected', Explanation::labelFor(0.40));
        $this->assertSame('unknown', Explanation::labelFor(0.20));
    }

    public function test_builds_a_canonical_explanation_from_a_recommendation(): void
    {
        $rec = new Recommendation(
            tenantId: 1, intentType: 'reorder', sku: 'SKU-1', quantity: 400,
            expectedValue: 22000, confidence: 0.86, risk: 0.15, rationale: 'Availability dropped 40%', objective: 'availability',
        );

        $exp = ExplanationBuilder::from($rec)->build();

        $this->assertSame('Reorder SKU-1 (400 units)', $exp->headline);
        $this->assertSame(22000.0, $exp->impact);
        $this->assertSame(0.86, $exp->confidence);
        $this->assertSame('established', $exp->confidenceLabel);
        $this->assertSame('availability', $exp->objective);
        $this->assertContains('Availability dropped 40%', $exp->evidence);
    }

    public function test_fluent_additions_and_confidence_override_relabel(): void
    {
        $rec = new Recommendation(tenantId: 1, intentType: 'reorder', expectedValue: 5000, confidence: 0.9);

        $exp = ExplanationBuilder::from($rec)
            ->withConfidence(0.5)                                   // e.g. after data-quality discount
            ->addEvidence('3 similar past cases succeeded')
            ->addReasons(['data: sales_daily stale'])
            ->withExpectedOutcome('Recover ~AED 3,500')
            ->build();

        $this->assertSame(0.5, $exp->confidence);
        $this->assertSame('suspected', $exp->confidenceLabel);      // relabelled from the override
        $this->assertContains('3 similar past cases succeeded', $exp->evidence);
        $this->assertContains('data: sales_daily stale', $exp->reasons);
        $this->assertSame('Recover ~AED 3,500', $exp->expectedOutcome);
    }

    public function test_maps_an_orchestration_outcome_into_the_contract(): void
    {
        $rec = new Recommendation(tenantId: 1, intentType: 'reorder', sku: 'SKU-1', expectedValue: 5000, confidence: 0.9);

        $outcome = new OrchestrationOutcome(
            mode: OrchestrationOutcome::MODE_QUEUED,
            level: 'auto',
            effectiveConfidence: 0.45,
            reasons: ['data: inventory stale', 'auto→approval: confidence 0.45 below threshold 0.8'],
        );

        $exp = ExplanationBuilder::fromOrchestration($rec, $outcome);

        $this->assertSame(0.45, $exp->confidence);
        $this->assertSame('suspected', $exp->confidenceLabel);
        $this->assertSame('Sent for human approval.', $exp->expectedOutcome);
        $this->assertContains('auto→approval: confidence 0.45 below threshold 0.8', $exp->reasons);
    }

    public function test_to_array_has_the_full_canonical_shape(): void
    {
        $rec = new Recommendation(tenantId: 1, intentType: 'transfer', expectedValue: 100, confidence: 0.7, risk: 0.2);
        $arr = ExplanationBuilder::from($rec)->build()->toArray();

        $this->assertSame(
            ['headline', 'intent_type', 'impact', 'confidence', 'confidence_label', 'risk', 'objective', 'evidence', 'reasons', 'expected_outcome'],
            array_keys($arr),
        );
    }
}
