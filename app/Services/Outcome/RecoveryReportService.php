<?php

namespace App\Services\Outcome;

use App\Models\Action;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RecoveryReportService — Feature 8 (recovery reporting)
 *
 * Deterministic tenant-level recovery aggregations for the reporting surfaces:
 * revenue at risk, observed recovery, investigations resolved, and recovery
 * broken down by cause / action / store, plus average times to action and
 * resolution. Each investigation's recovery is attributed to a single bucket to
 * avoid double counting.
 */
class RecoveryReportService
{
    public function summary(int $tenantId): array
    {
        $row = InvestigationOutcome::where('tenant_id', $tenantId)
            ->selectRaw('
                COALESCE(SUM(revenue_at_risk), 0)   AS total_at_risk,
                COALESCE(SUM(observed_recovery), 0) AS total_recovered,
                COUNT(*)                            AS outcomes,
                COUNT(*) FILTER (WHERE was_false_positive) AS fp_count
            ')->first();

        $atRisk    = (float) ($row->total_at_risk ?? 0);
        $recovered = (float) ($row->total_recovered ?? 0);

        $resolved = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])
            ->count();

        return [
            'total_at_risk'          => $atRisk,
            'total_recovered'        => $recovered,
            'recovery_rate'          => $atRisk > 0 ? round(($recovered / $atRisk) * 100, 1) : null,
            'investigations_resolved'=> $resolved,
            'outcomes_recorded'      => (int) ($row->outcomes ?? 0),
            'false_positives'        => (int) ($row->fp_count ?? 0),
        ];
    }

    /**
     * Recovery grouped by cause (highest-severity anomaly rule per investigation).
     */
    public function byCause(int $tenantId): array
    {
        $buckets = [];
        $outcomes = InvestigationOutcome::where('tenant_id', $tenantId)
            ->with(['investigation.anomalies'])
            ->get();

        foreach ($outcomes as $outcome) {
            $inv = $outcome->investigation;
            if (! $inv) {
                continue;
            }
            $rule = optional($inv->anomalies->sortByDesc('detected_at')->first())->rule_type ?? 'uncategorised';
            $this->accumulate($buckets, $rule, $outcome);
        }

        return $this->finalise($buckets);
    }

    /**
     * Recovery grouped by the completed action type.
     */
    public function byAction(int $tenantId): array
    {
        $buckets = [];
        $outcomes = InvestigationOutcome::where('tenant_id', $tenantId)
            ->with(['investigation.actions'])
            ->get();

        foreach ($outcomes as $outcome) {
            $inv = $outcome->investigation;
            if (! $inv) {
                continue;
            }
            $action = $inv->actions->where('status', Action::STATUS_COMPLETED)->sortByDesc('completed_at')->first();
            $key = $action ? ($action->action_type ?? 'other') : 'no_action';
            $this->accumulate($buckets, $key, $outcome);
        }

        return $this->finalise($buckets);
    }

    /**
     * Recovery grouped by store.
     */
    public function byStore(int $tenantId): array
    {
        $rows = InvestigationOutcome::query()
            ->join('investigations', 'investigations.id', '=', 'investigation_outcomes.investigation_id')
            ->leftJoin('stores', 'stores.id', '=', 'investigations.primary_store_id')
            ->where('investigation_outcomes.tenant_id', $tenantId)
            ->groupBy('stores.name')
            ->selectRaw("COALESCE(stores.name, 'Unassigned') AS label")
            ->selectRaw('COALESCE(SUM(investigation_outcomes.observed_recovery), 0) AS recovered')
            ->selectRaw('COALESCE(SUM(investigation_outcomes.revenue_at_risk), 0)  AS at_risk')
            ->selectRaw('COUNT(*) AS count')
            ->orderByDesc('recovered')
            ->get();

        return $rows->map(fn ($r) => [
            'label'     => $r->label,
            'recovered' => (float) $r->recovered,
            'at_risk'   => (float) $r->at_risk,
            'count'     => (int) $r->count,
        ])->all();
    }

    /**
     * Average time-to-action and time-to-resolution (hours), tenant-wide.
     */
    public function averageTimes(int $tenantId): array
    {
        $resolved = Investigation::where('tenant_id', $tenantId)
            ->whereNotNull('opened_at')
            ->whereNotNull('resolved_at')
            ->get(['opened_at', 'resolved_at', 'id']);

        $resolutionHours = $resolved
            ->map(fn ($i) => Carbon::parse($i->opened_at)->diffInHours(Carbon::parse($i->resolved_at)))
            ->filter(fn ($h) => $h >= 0);

        // Time to first action = first action created_at - opened_at
        $firstActions = DB::table('actions')
            ->join('investigations', 'investigations.id', '=', 'actions.investigation_id')
            ->where('investigations.tenant_id', $tenantId)
            ->whereNotNull('investigations.opened_at')
            ->selectRaw('investigations.id AS inv_id, investigations.opened_at AS opened_at, MIN(actions.created_at) AS first_action_at')
            ->groupBy('investigations.id', 'investigations.opened_at')
            ->get();

        $actionHours = $firstActions
            ->map(fn ($r) => Carbon::parse($r->opened_at)->diffInHours(Carbon::parse($r->first_action_at)))
            ->filter(fn ($h) => $h >= 0);

        return [
            'avg_hours_to_action'     => $actionHours->isNotEmpty() ? round($actionHours->avg(), 1) : null,
            'avg_hours_to_resolution' => $resolutionHours->isNotEmpty() ? round($resolutionHours->avg(), 1) : null,
        ];
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function accumulate(array &$buckets, string $key, InvestigationOutcome $outcome): void
    {
        if (! isset($buckets[$key])) {
            $buckets[$key] = ['recovered' => 0.0, 'at_risk' => 0.0, 'count' => 0];
        }
        $buckets[$key]['recovered'] += (float) ($outcome->observed_recovery ?? 0);
        $buckets[$key]['at_risk']   += (float) ($outcome->revenue_at_risk ?? 0);
        $buckets[$key]['count']++;
    }

    private function finalise(array $buckets): array
    {
        $out = [];
        foreach ($buckets as $label => $b) {
            $out[] = [
                'label'     => $label,
                'recovered' => round($b['recovered'], 2),
                'at_risk'   => round($b['at_risk'], 2),
                'count'     => $b['count'],
            ];
        }
        usort($out, fn ($a, $b) => $b['recovered'] <=> $a['recovered']);
        return $out;
    }
}
