<?php

namespace Tests\Feature;

use App\Platform\Explainability\Explanation;
use App\Platform\Explainability\ExplanationBuilder;
use App\Platform\Orchestration\OrchestrationOutcome;
use App\Platform\Recommendation\Recommendation;
use Tests\TestCase;

/**
 * P4.5 — the universal explanation contract: one shape, assembled from the
 * platform's pieces, with the shared confidence vocabulary.
 */
class ExplainabilityTest extends TestCase
{
    public function test_confidence_label_vocabulary(): void
    {
        $this->assertSame('established', Explanation::labelFor(0.9));
        $this->assertSame('probable', Explanation::labelFor(0.7));
        $this->assertSame('suspected', Explanation::labelFor(0.4));
        $this->assertSame('unknown', Explanation::labelFor(0.2));
    }

    public function test_builds_a_canonical_explanation_from_a_recommendation(): void
    {
        $rec = new Recommendation(
            tenantId: 1, intentType: 'reorder', sku: 'SKU-1', quantity: 400,
            expectedValue: 22000, confidence: 0.86, risk: 0.15, rationale: 'availability collapsed', objective: 'availability',
        );

        $e = ExplanationBuilder::from($rec)
            ->addEvidence(['18 similar cases recovered 74%'])
            ->addReasons(['data: sales_daily clean'])
            ->build();

        $this->assertSame('Reorder SKU-1 (400 units)', $e->headline);
        $this->assertSame(22000.0, $e->impact);
        $this->assertSame('established', $e->confidenceLabel); // 0.86 ≥ 0.85
        $this->assertSame('availability', $e->objective);
        $this->assertContains('availability collapsed', $e->evidence);       // from rationale
        $this->assertContains('18 similar cases recovered 74%', $e->evidence);
        $this->assertContains('data: sales_daily clean', $e->reasons);

        // canonical shape
        $this->assertEqualsCanonicalizing(
            ['headline', 'intent_type', 'impact', 'confidence', 'confidence_label', 'risk', 'objective', 'evidence', 'reasons', 'expected_outcome'],
            array_keys($e->toArray()),
        );
    }

    public function test_adjusted_confidence_relabels(): void
    {
        $rec = new Recommendation(tenantId: 1, intentType: 'reorder', expectedValue: 1000, confidence: 0.9);

        // A data/memory adjustment pulls effective confidence down to 0.45 → relabelled.
        $e = ExplanationBuilder::from($rec)->withConfidence(0.45)->build();

        $this->assertSame(0.45, $e->confidence);
        $this->assertSame('suspected', $e->confidenceLabel);
    }

    public function test_from_orchestration_outcome(): void
    {
        $rec = new Recommendation(tenantId: 1, intentType: 'reorder', sku: 'SKU-1', quantity: 100, expectedValue: 5000, confidence: 0.9);

        $outcome = new OrchestrationOutcome(
            mode: OrchestrationOutcome::MODE_QUEUED,
            level: 'auto',
            effectiveConfidence: 0.45,
            reasons: ['memory: 2 similar cases, past success 0', 'auto→approval: confidence 0.45 below threshold 0.8'],
        );

        $e = ExplanationBuilder::fromOrchestration($rec, $outcome);

        $this->assertSame(0.45, $e->confidence);
        $this->assertSame('suspected', $e->confidenceLabel);
        $this->assertStringContainsString('queued', $e->expectedOutcome);
        $this->assertTrue(collect($e->reasons)->contains(fn ($r) => str_contains($r, 'below threshold')));
    }
}
