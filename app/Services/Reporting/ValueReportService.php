<?php

namespace App\Services\Reporting;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\CycleCount;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Services\Anomaly\AnomalyFeedback;
use App\Services\Anomaly\AnomalyDismissal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W11 — "Value delivered": what Autnyx found, what the team did about it, and
 * what was MEASURED as a result, for one period. The monthly report to the
 * customer's leadership, and the proof a renewal rests on.
 *
 *   found     investigations opened, lost revenue and capital identified;
 *   acted     actions completed, investigations resolved, time to first action;
 *   measured  revenue recovered against the counterfactual, stock value
 *             released (beside it, never added), results still being
 *             measured; typed-in figures are reported as claims, apart;
 *   counts    what cycle counts corrected;
 *   accuracy  the team's one-click answers — precision on their own data.
 *
 * Every figure is a database aggregate. Nothing is estimated here.
 */
class ValueReportService
{
    /** @return array<string,mixed> */
    public function build(int $tenantId, Carbon $from, Carbon $to): array
    {
        $inv = Investigation::where('tenant_id', $tenantId)->whereBetween('opened_at', [$from, $to]);
        $found = [
            'investigations' => (clone $inv)->count(),
            'lost_revenue'   => round((float) (clone $inv)->sum('revenue_at_risk'), 2),
            'capital'        => round((float) (clone $inv)->sum('capital_at_risk'), 2),
            'anomalies'      => Anomaly::where('tenant_id', $tenantId)->whereBetween('detected_at', [$from, $to])
                ->where(fn ($q) => $q->whereNull('dismiss_reason')->orWhere('dismiss_reason', '!=', AnomalyDismissal::REASON_SUPERSEDED))->count(),
        ];

        $actionsDone = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', Action::STATUS_COMPLETED)->whereBetween('completed_at', [$from, $to]);
        $firstAction = DB::selectOne(
            "SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY h) AS median_h, COUNT(*) AS n FROM (
                SELECT EXTRACT(EPOCH FROM (MIN(a.completed_at) - i.opened_at)) / 3600 AS h
                  FROM actions a JOIN investigations i ON i.id = a.investigation_id
                 WHERE i.tenant_id = ? AND a.status = 'completed' AND i.opened_at IS NOT NULL
                 GROUP BY i.id, i.opened_at
                HAVING MIN(a.completed_at) BETWEEN ? AND ?
             ) t WHERE h >= 0",
            [$tenantId, $from, $to]
        );
        $acted = [
            'actions_completed'       => (clone $actionsDone)->count(),
            'investigations_resolved' => Investigation::where('tenant_id', $tenantId)
                ->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])
                ->whereBetween('resolved_at', [$from, $to])->count(),
            'median_hours_to_action'  => $firstAction?->median_h !== null ? round((float) $firstAction->median_h, 1) : null,
        ];

        $out = InvestigationOutcome::where('tenant_id', $tenantId)->whereBetween('recorded_at', [$from, $to]);
        $measured = [
            'revenue'          => round((float) (clone $out)->where('measured_recovery', '>', 0)->sum('measured_recovery'), 2),
            'revenue_count'    => (clone $out)->where('measured_recovery', '>', 0)->count(),
            'capital'          => round((float) (clone $out)->where('measured_capital', '>', 0)->sum('measured_capital'), 2),
            'capital_count'    => (clone $out)->where('measured_capital', '>', 0)->count(),
            'no_change'        => (clone $out)->where('outcome_state', InvestigationOutcome::STATE_NO_MATERIAL_CHANGE)->count(),
            'claimed'          => round((float) (clone $out)->whereNull('measured_recovery')->where('observed_recovery', '>', 0)->sum('observed_recovery'), 2),
            'claimed_count'    => (clone $out)->whereNull('measured_recovery')->where('observed_recovery', '>', 0)->count(),
            // Being measured now (any period): the pipeline of results to come.
            'monitoring'       => InvestigationOutcome::where('tenant_id', $tenantId)
                ->where('outcome_state', InvestigationOutcome::STATE_MONITORING)->count(),
        ];

        $cc = CycleCount::where('tenant_id', $tenantId)->where('status', CycleCount::STATUS_COUNTED)->whereBetween('counted_at', [$from, $to]);
        $counts = [
            'counted'          => (clone $cc)->count(),
            'corrected'        => (clone $cc)->where('variance_qty', '!=', 0)->count(),
            'variance_value'   => round((float) (clone $cc)->selectRaw('COALESCE(SUM(ABS(variance_value)), 0) AS v')->value('v'), 2),
        ];

        $fb = app(AnomalyFeedback::class)->precisionByRule($tenantId, $from, $to);
        $real = array_sum(array_column($fb, 'real'));
        $notReal = array_sum(array_column($fb, 'not_real'));
        $accuracy = [
            'answered'  => $real + $notReal,
            'real'      => $real,
            'not_real'  => $notReal,
            'precision' => $real + $notReal > 0 ? round(100 * $real / ($real + $notReal), 1) : null,
            'by_rule'   => $fb,
        ];

        $wins = InvestigationOutcome::where('tenant_id', $tenantId)->whereBetween('recorded_at', [$from, $to])
            ->where(fn ($q) => $q->where('measured_recovery', '>', 0)->orWhere('measured_capital', '>', 0))
            ->with(['investigation.primaryStore', 'investigation.actions'])
            ->orderByRaw('COALESCE(measured_recovery, 0) + COALESCE(measured_capital, 0) DESC')
            ->limit(10)->get()
            ->map(function (InvestigationOutcome $o) {
                $i = $o->investigation;
                $act = $i?->actions->where('status', Action::STATUS_COMPLETED)->sortByDesc('completed_at')->first();

                return [
                    'investigation_id' => $o->investigation_id,
                    'title'            => (string) ($i?->title ?? ''),
                    'store'            => (string) ($i?->primaryStore?->name ?? ''),
                    'sku'              => (string) ($i?->primary_sku ?? ''),
                    'action'           => $act ? (Action::TYPE_LABELS[$act->action_type] ?? $act->action_type) : '',
                    'revenue'          => (float) ($o->measured_recovery ?? 0),
                    'capital'          => (float) ($o->measured_capital ?? 0),
                    'state'            => $o->getOutcomeStateLabel(),
                ];
            })->all();

        return compact('found', 'acted', 'measured', 'counts', 'accuracy', 'wins');
    }
}
