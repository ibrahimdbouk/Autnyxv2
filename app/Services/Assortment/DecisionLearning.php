<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\DecisionCase;
use App\Platform\Memory\CaseRecorder;
use App\Platform\Recommendation\Recommendation;
use Illuminate\Support\Facades\DB;

/**
 * v1 gap 4 + Phase 1 learning records — every range decision a person makes
 * goes into the platform's decision memory (DecisionCase), with the figures it
 * was made on (value, confidence, the transferable-demand share and its model
 * version), and its measured result is written back when it arrives. That is
 * what later calibration reads: expected against measured, per rule.
 *
 * Nothing here changes a rule. Calibration produces versioned proposals an
 * admin approves; production rules never change silently.
 */
class DecisionLearning
{
    public const INTENTS = [
        AssortmentGap::TYPE_ADD             => 'range_add',
        AssortmentGap::TYPE_DELIST          => 'range_delist',
        AssortmentGap::TYPE_STOCKOUT_HIDDEN => 'restore_availability',
    ];

    public const OBJECTIVES = [
        AssortmentGap::TYPE_ADD             => 'sales',
        AssortmentGap::TYPE_DELIST          => 'working_capital',
        AssortmentGap::TYPE_STOCKOUT_HIDDEN => 'availability',
    ];

    public function __construct(private CaseRecorder $recorder) {}

    public static function ref(AssortmentGap $gap): string
    {
        return 'assortment_gap:' . $gap->id;
    }

    public function decided(AssortmentGap $gap, bool $accepted): void
    {
        try {
            $e = $gap->evidence ?? [];
            $rec = new Recommendation(
                tenantId: (int) $gap->tenant_id,
                intentType: self::INTENTS[$gap->type] ?? $gap->type,
                sku: $gap->sku,
                storeId: (int) $gap->store_id,
                // In sales a year — the unit the result is measured in, so expected and measured compare.
                expectedValue: (float) ($e['expected_sales_change_per_year'] ?? $gap->value_mid),
                confidence: (float) $gap->confidence,
                risk: round(1 - (float) $gap->confidence, 4),
                rationale: $gap->headline(),
                objective: self::OBJECTIVES[$gap->type] ?? null,
                source: 'assortment',
            );
            $this->recorder->record(
                $rec,
                situation: [
                    'app' => 'assortment', 'type' => $gap->type, 'peer_group' => $gap->peer_group,
                    'category' => $e['category'] ?? null, 'tier' => $gap->confidence_tier, 'lifecycle' => $e['lifecycle'] ?? null,
                ],
                evidence: [
                    'value' => [$gap->value_low, $gap->value_mid, $gap->value_high], 'value_basis' => $e['value_basis'] ?? null,
                    'transfer' => $e['transfer'] ?? null, 'qualifying_peers' => $e['qualifying_peers'] ?? null,
                    'as_of_date' => $gap->as_of_date?->toDateString(),
                ],
                decision: $accepted ? DecisionCase::DECISION_ADOPTED : DecisionCase::DECISION_REJECTED,
                actionRef: self::ref($gap),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** A range plan decided as a whole (intent range_plan; the expected change is the category's sales a year). */
    public function planDecided(\App\Models\AssortmentPlan $plan, bool $accepted): void
    {
        try {
            $impact = $plan->impact ?? [];
            $rec = new Recommendation(
                tenantId: (int) $plan->tenant_id,
                intentType: 'range_plan',
                storeId: (int) $plan->store_id,
                expectedValue: (float) ($impact['sales'][1] ?? $plan->value_mid),
                confidence: (float) $plan->confidence,
                risk: round(1 - (float) $plan->confidence, 4),
                rationale: $plan->headline(),
                objective: $plan->objective,
                source: 'assortment',
            );
            $this->recorder->record(
                $rec,
                situation: ['app' => 'assortment', 'type' => 'plan', 'category' => $plan->category, 'role' => $plan->role,
                    'objective' => $plan->objective, 'tier' => $plan->confidence_tier],
                evidence: ['impact' => $impact, 'changes' => array_map(fn ($c) => array_intersect_key($c, array_flip(['kind', 'sku', 'out_sku', 'sales', 'ticked'])), $plan->actionable()),
                    'optimizer_version' => $plan->optimizer_version, 'constraints' => array_diff_key($plan->constraints ?? [], ['left_out' => 1])],
                decision: $accepted ? DecisionCase::DECISION_ADOPTED : DecisionCase::DECISION_REJECTED,
                actionRef: 'assortment_plan:' . $plan->id,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function planMeasured(\App\Models\AssortmentPlan $plan): void
    {
        try {
            $case = DecisionCase::where('tenant_id', $plan->tenant_id)->where('action_ref', 'assortment_plan:' . $plan->id)->latest('id')->first();
            $m = $plan->measurement ?? [];
            if ($case && $m !== []) {
                $this->recorder->recordOutcome($case, (string) ($m['verdict'] ?? DecisionCase::OUTCOME_PARTIAL), (float) ($m['uplift_per_year'] ?? 0), $m);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Write the measured result back to the decision's case. */
    public function measured(AssortmentGap $gap): void
    {
        try {
            $case = DecisionCase::where('tenant_id', $gap->tenant_id)->where('action_ref', self::ref($gap))->latest('id')->first();
            $m = $gap->measurement ?? [];
            if (! $case || $m === []) {
                return;
            }
            $this->recorder->recordOutcome($case, (string) ($m['verdict'] ?? DecisionCase::OUTCOME_PARTIAL), (float) ($m['uplift_per_year'] ?? 0), $m);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * How each rule is doing, from decision memory and the review gate:
     * reviewed sensible, accepted, measured, worked, expected against measured.
     *
     * @return array<string,array<string,mixed>> type => figures
     */
    public function performance(int $tenantId): array
    {
        $out = [];
        foreach (self::INTENTS as $type => $intent) {
            $cases = DB::table('decision_cases')->where('tenant_id', $tenantId)->where('intent_type', $intent)
                ->where('action_ref', 'like', 'assortment_gap:%');
            $decided  = (clone $cases)->whereIn('decision', [DecisionCase::DECISION_ADOPTED, DecisionCase::DECISION_REJECTED])->count();
            $adopted  = (clone $cases)->where('decision', DecisionCase::DECISION_ADOPTED)->count();
            $resolved = (clone $cases)->whereIn('outcome_status', DecisionCase::RESOLVED);
            $measured = (clone $resolved)->count();
            $worked   = (clone $resolved)->whereIn('outcome_status', [DecisionCase::OUTCOME_SUCCESS, DecisionCase::OUTCOME_PARTIAL])->count();
            $sums     = (clone $resolved)->selectRaw('COALESCE(SUM(expected_value), 0) AS e, COALESCE(SUM(realized_value), 0) AS r')->first();

            $gate = DB::table('assortment_gaps')->where('tenant_id', $tenantId)->where('type', $type)->whereNotNull('review_verdict');
            $reviewed = (clone $gate)->count();
            $sensible = (clone $gate)->where('review_verdict', AssortmentGap::VERDICT_SENSIBLE)->count();

            $out[$type] = [
                'reviewed'      => $reviewed,
                'sensible_rate' => $reviewed > 0 ? round($sensible / $reviewed, 3) : null,
                'decided'       => $decided,
                'accept_rate'   => $decided > 0 ? round($adopted / $decided, 3) : null,
                'measured'      => $measured,
                'worked_rate'   => $measured > 0 ? round($worked / $measured, 3) : null,
                'expected'      => round((float) $sums->e, 2),
                'realized'      => round((float) $sums->r, 2),
            ];
        }

        return $out;
    }
}
