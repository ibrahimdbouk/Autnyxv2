<?php

namespace Tests\Feature;

use App\Models\DecisionCase;
use App\Platform\Memory\CaseRecorder;
use App\Platform\Memory\CaseRetrieval;
use App\Platform\Memory\Similarity;
use App\Platform\Recommendation\Recommendation;
use Tests\TestCase;

/**
 * P4.3 — decision memory: record the arc, close it with an outcome, retrieve
 * similar past situations, and summarise how they turned out.
 */
class DecisionMemoryTest extends TestCase
{
    public function test_cosine_similarity_basics(): void
    {
        $this->assertSame(1.0, Similarity::cosine(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2]));
        $this->assertSame(0.0, Similarity::cosine(['a' => 1], ['b' => 1]));           // orthogonal
        $this->assertSame(0.0, Similarity::cosine([], ['a' => 1]));                    // empty
        $this->assertGreaterThan(0.9, Similarity::cosine(['a' => 10, 'b' => 1], ['a' => 12, 'b' => 1]));
    }

    public function test_record_and_close_the_arc(): void
    {
        $tenant = $this->createTenant();
        $recorder = app(CaseRecorder::class);

        $case = $recorder->record(
            new Recommendation(tenantId: $tenant->id, intentType: 'reorder', sku: 'SKU-1', expectedValue: 10000, confidence: 0.8, risk: 0.2),
            situation: ['drop_pct' => 40, 'days_out' => 3],
            evidence: ['sales_down', 'stock_low'],
        );

        $this->assertSame('reorder', $case->intent_type);
        $this->assertSame(10000.0, $case->expected_value);
        $this->assertSame(DecisionCase::OUTCOME_PENDING, $case->outcome_status);
        $this->assertFalse($case->isResolved());

        $recorder->recordOutcome($case, DecisionCase::OUTCOME_SUCCESS, realizedValue: 7500);

        $case->refresh();
        $this->assertSame(DecisionCase::OUTCOME_SUCCESS, $case->outcome_status);
        $this->assertSame(0.75, $case->realization_rate); // 7500 / 10000
        $this->assertTrue($case->isResolved());
    }

    public function test_similar_ranks_by_situation_closeness(): void
    {
        $tenant = $this->createTenant();
        $recorder = app(CaseRecorder::class);
        $rec = fn () => new Recommendation(tenantId: $tenant->id, intentType: 'reorder', expectedValue: 1000);

        $recorder->record($rec(), situation: ['drop_pct' => 40, 'days_out' => 3]);   // near
        $recorder->record($rec(), situation: ['drop_pct' => 5, 'days_out' => 30]);   // far

        $ranked = app(CaseRetrieval::class)->similar($tenant->id, ['drop_pct' => 42, 'days_out' => 3]);

        $this->assertSame(['drop_pct' => 40, 'days_out' => 3], $ranked->first()['case']->situation);
        $this->assertGreaterThan($ranked->last()['similarity'], $ranked->first()['similarity']);
    }

    public function test_success_rate_over_similar_resolved_cases(): void
    {
        $tenant = $this->createTenant();
        $recorder = app(CaseRecorder::class);
        $situation = ['drop_pct' => 40, 'days_out' => 3];
        $rec = fn () => new Recommendation(tenantId: $tenant->id, intentType: 'reorder', expectedValue: 1000);

        $c1 = $recorder->record($rec(), situation: $situation);
        $c2 = $recorder->record($rec(), situation: ['drop_pct' => 38, 'days_out' => 4]);
        $c3 = $recorder->record($rec(), situation: ['drop_pct' => 41, 'days_out' => 2]);
        $recorder->recordOutcome($c1, DecisionCase::OUTCOME_SUCCESS, 900);
        $recorder->recordOutcome($c2, DecisionCase::OUTCOME_SUCCESS, 800);
        $recorder->recordOutcome($c3, DecisionCase::OUTCOME_FAILURE, 0);

        $summary = app(CaseRetrieval::class)->successRate($tenant->id, $situation, 'reorder');

        $this->assertSame(3, $summary['n']);
        $this->assertSame(0.667, $summary['success_rate']);        // 2 of 3
        $this->assertSame(0.567, $summary['avg_realization']);     // (0.9 + 0.8 + 0.0) / 3
    }

    public function test_memory_is_tenant_scoped(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        app(CaseRecorder::class)->record(
            new Recommendation(tenantId: $a->id, intentType: 'reorder', expectedValue: 1000),
            situation: ['x' => 1],
        );

        $this->assertCount(1, app(CaseRetrieval::class)->similar($a->id, ['x' => 1]));
        $this->assertCount(0, app(CaseRetrieval::class)->similar($b->id, ['x' => 1]));
    }
}
