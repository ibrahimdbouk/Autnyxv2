<?php

namespace Tests\Feature;

use App\Models\DecisionCase;
use App\Models\EvaluationSnapshot;
use App\Platform\Evaluation\EvaluationService;
use App\Platform\Evaluation\IntelligenceScorecard;
use App\Platform\Memory\CaseRecorder;
use App\Platform\Recommendation\Recommendation;
use Tests\TestCase;

/**
 * P4.4 — the intelligence quality scorecard computed from decision memory (P4.3),
 * including the calibration gap (predicted confidence vs actual success).
 */
class EvaluationTest extends TestCase
{
    private function case(int $tenantId, string $intent, float $confidence, string $status, float $realized): void
    {
        $recorder = app(CaseRecorder::class);
        $case = $recorder->record(
            new Recommendation(tenantId: $tenantId, intentType: $intent, expectedValue: 1000, confidence: $confidence),
            situation: ['x' => 1],
        );
        $recorder->recordOutcome($case, $status, $realized);
    }

    public function test_scorecard_metrics_and_calibration(): void
    {
        $tenant = $this->createTenant();
        // reorder: two successes (0.9, 0.8 realization) and one failure; all at confidence 0.8.
        $this->case($tenant->id, 'reorder', 0.8, DecisionCase::OUTCOME_SUCCESS, 900);
        $this->case($tenant->id, 'reorder', 0.8, DecisionCase::OUTCOME_SUCCESS, 800);
        $this->case($tenant->id, 'reorder', 0.8, DecisionCase::OUTCOME_FAILURE, 0);
        // transfer: one success at confidence 0.5.
        $this->case($tenant->id, 'transfer', 0.5, DecisionCase::OUTCOME_SUCCESS, 1000);

        $card = app(IntelligenceScorecard::class)->compute($tenant->id);

        $this->assertSame(4, $card['overall']['n']);
        $this->assertSame(4, $card['overall']['resolved']);
        $this->assertSame(1.0, $card['overall']['adoption_rate']);

        $reorder = collect($card['by_intent_type'])->firstWhere('dim_key', 'reorder');
        $this->assertSame(3, $reorder['n']);
        $this->assertSame(0.667, $reorder['success_rate']);        // 2 of 3
        $this->assertSame(0.567, $reorder['avg_realization']);     // (0.9 + 0.8 + 0.0) / 3
        $this->assertSame(0.8, $reorder['avg_confidence']);
        $this->assertSame(0.133, $reorder['calibration_gap']);    // 0.8 − 0.667 = overconfident

        // both intent types present
        $this->assertEqualsCanonicalizing(
            ['reorder', 'transfer'],
            collect($card['by_intent_type'])->pluck('dim_key')->all(),
        );
    }

    public function test_snapshot_persists_one_row_per_dimension(): void
    {
        $tenant = $this->createTenant();
        $this->case($tenant->id, 'reorder', 0.8, DecisionCase::OUTCOME_SUCCESS, 900);
        $this->case($tenant->id, 'transfer', 0.5, DecisionCase::OUTCOME_FAILURE, 0);

        $written = app(EvaluationService::class)->snapshot($tenant->id);

        // overall (1) + intent_type (reorder, transfer = 2) + objective ((none) = 1) = 4
        $this->assertCount(4, $written);
        $this->assertSame(4, EvaluationSnapshot::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, EvaluationSnapshot::where('tenant_id', $tenant->id)
            ->where('dimension', EvaluationSnapshot::DIM_OVERALL)->count());
    }

    public function test_empty_tenant_scores_nothing(): void
    {
        $tenant = $this->createTenant();
        $card = app(IntelligenceScorecard::class)->compute($tenant->id);

        $this->assertSame(0, $card['overall']['n']);
        $this->assertNull($card['overall']['success_rate']);
        $this->assertSame([], $card['by_intent_type']);
    }
}
