<?php

namespace App\Services\Quality;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Models\InvestigationOutcome;
use App\Models\Suppression;
use App\Services\Outcome\RecoveryReportService;
use App\Services\Recovery\AnomalyRecoveryService;
use Illuminate\Support\Facades\DB;

/**
 * QualityMetricsService — Feature 9 (Investigation Confidence & Quality Center)
 *
 * Every metric derives from actual lifecycle events (anomalies, investigations,
 * actions, outcomes, audit timestamps). No AI-accuracy claims are made without
 * ground truth; a composite quality score is only produced when the sample is
 * statistically meaningful.
 */
class QualityMetricsService
{
    /** Minimum resolved investigations before a composite score is meaningful. */
    private const MIN_SAMPLE_FOR_SCORE = 20;

    public function report(int $tenantId): array
    {
        return [
            'funnel'          => $this->funnel($tenantId),
            'rates'           => $this->rates($tenantId),
            'recovery'        => $this->recoveryLifecycle($tenantId),
            'evidence'        => $this->evidenceQuality($tenantId),
            'action_quality'  => $this->actionQuality($tenantId),
            'rule_performance'=> $this->rulePerformance($tenantId),
            'noisy'           => $this->noisy($tenantId),
            'times'           => app(RecoveryReportService::class)->averageTimes($tenantId),
            'overall_score'   => $this->overallScore($tenantId),
        ];
    }

    public function funnel(int $tenantId): array
    {
        $anomalies       = Anomaly::where('tenant_id', $tenantId)->count();
        $investigations  = Investigation::where('tenant_id', $tenantId)->count();
        $accepted        = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_IN_PROGRESS, Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])
            ->count();
        $actionable      = Investigation::where('tenant_id', $tenantId)->whereHas('actions')->count();
        $actionTaken     = Investigation::where('tenant_id', $tenantId)
            ->whereHas('actions', fn ($q) => $q->where('status', Action::STATUS_COMPLETED))->count();
        $resolved        = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])->count();
        $recovered       = InvestigationOutcome::where('tenant_id', $tenantId)
            ->whereIn('outcome_state', [InvestigationOutcome::STATE_OBSERVED_RECOVERY, InvestigationOutcome::STATE_PARTIAL_RECOVERY])
            ->count();

        return [
            ['stage' => 'Potential anomalies', 'count' => $anomalies],
            ['stage' => 'Investigations',      'count' => $investigations],
            ['stage' => 'Reviewed / accepted', 'count' => $accepted],
            ['stage' => 'Actionable',          'count' => $actionable],
            ['stage' => 'Action taken',        'count' => $actionTaken],
            ['stage' => 'Resolved',            'count' => $resolved],
            ['stage' => 'Observed recovery',   'count' => $recovered],
        ];
    }

    public function rates(int $tenantId): array
    {
        $totalAnomalies = Anomaly::where('tenant_id', $tenantId)->count();
        $fp             = Anomaly::where('tenant_id', $tenantId)->where('is_false_positive', true)->count();
        $dismissed      = Anomaly::where('tenant_id', $tenantId)->whereNotNull('dismissed_at')->count();
        $dismissedNonFp = max(0, $dismissed - $fp);
        $accepted       = max(0, $totalAnomalies - $dismissed);

        $totalInv   = Investigation::where('tenant_id', $tenantId)->count();
        $snoozed    = Investigation::where('tenant_id', $tenantId)->whereNotNull('snoozed_until')->where('snoozed_until', '>', now())->count();
        $resolvedInv= Investigation::where('tenant_id', $tenantId)->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])->count();
        $narrated   = Investigation::where('tenant_id', $tenantId)->whereNotNull('ai_generated_at')->count();
        $established = Investigation::where('tenant_id', $tenantId)->where('ai_confidence', Investigation::CONFIDENCE_ESTABLISHED)->count();

        $activeSuppressions = Suppression::currentlyActive()->where('tenant_id', $tenantId)->count();
        $suppressedMatches  = (int) Suppression::where('tenant_id', $tenantId)->sum('match_count');

        $totalActions     = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId))->count();
        $completedActions = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', Action::STATUS_COMPLETED)->count();

        return [
            'acceptance_rate'        => $this->pct($accepted, $totalAnomalies),
            'false_positive_rate'    => $this->pct($fp, $totalAnomalies),
            'dismissal_rate'         => $this->pct($dismissedNonFp, $totalAnomalies),
            'snooze_rate'            => $this->pct($snoozed, $totalInv),
            'active_suppressions'    => $activeSuppressions,
            'suppressed_matches'     => $suppressedMatches,
            'established_cause_rate' => $this->pct($established, $narrated),
            'action_completion_rate' => $this->pct($completedActions, $totalActions),
            'resolution_rate'        => $this->pct($resolvedInv, $totalInv),
            'counts'                 => [
                'anomalies'      => $totalAnomalies,
                'investigations' => $totalInv,
                'actions'        => $totalActions,
                'resolved'       => $resolvedInv,
            ],
        ];
    }

    /**
     * Recovery-lifecycle health (R3) — how the deterministic anomaly lifecycle
     * (R1/R2) is actually moving: how many subjects are live vs. cleared, how
     * fast they clear, how often they recur, and how much value has been observed
     * clearing. Every figure is DB truth written by the reconciler — OBSERVED,
     * never attributed to an action.
     */
    public function recoveryLifecycle(int $tenantId): array
    {
        $states = DB::table('anomalies')
            ->where('tenant_id', $tenantId)
            ->whereNull('dismissed_at')
            ->groupBy('lifecycle_state')
            ->selectRaw('lifecycle_state, COUNT(*) AS cnt')
            ->pluck('cnt', 'lifecycle_state')
            ->all();

        $open       = (int) ($states[Anomaly::LIFECYCLE_OPEN] ?? 0);
        $persisting = (int) ($states[Anomaly::LIFECYCLE_PERSISTING] ?? 0);
        $clearing   = (int) ($states[Anomaly::LIFECYCLE_CLEARING] ?? 0);
        $resolved   = (int) ($states[Anomaly::LIFECYCLE_RESOLVED] ?? 0);
        $active     = $open + $persisting + $clearing;

        // Mean days open→resolved, genuinely observed episodes only (Postgres).
        $meanDays = DB::table('anomalies')
            ->where('tenant_id', $tenantId)
            ->where('lifecycle_state', Anomaly::LIFECYCLE_RESOLVED)
            ->where(fn ($w) => $w->where('backfilled', false)->orWhereNull('backfilled'))
            ->whereNotNull('resolved_at')
            ->whereNotNull('first_seen_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (resolved_at - first_seen_at)) / 86400.0) AS d')
            ->value('d');

        // Recurrence: episodes that are a re-emergence of an earlier resolved one.
        $recurrences = (int) Anomaly::where('tenant_id', $tenantId)
            ->whereNotNull('previous_episode_id')
            ->count();

        $recoverySummary = app(AnomalyRecoveryService::class)->summary($tenantId);

        return [
            'active'              => $active,
            'open'                => $open,
            'persisting'          => $persisting,
            'clearing'            => $clearing,
            'resolved'            => $resolved,
            'clearing_share'      => $this->pct($clearing, $active),
            'clear_rate'          => $recoverySummary['clear_rate'],
            'observed_total'      => (float) $recoverySummary['observed_total'],
            'observed_mtd'        => (float) $recoverySummary['observed_mtd'],
            'active_at_risk'      => (float) $recoverySummary['active_at_risk'],
            'backfilled_excluded' => (int) $recoverySummary['backfilled_excluded'],
            'mean_days_to_clear'  => $meanDays !== null ? round((float) $meanDays, 1) : null,
            'recurrences'         => $recurrences,
        ];
    }

    public function evidenceQuality(int $tenantId): array
    {
        $rows = InvestigationEvidence::query()
            ->join('investigations', 'investigations.id', '=', 'investigation_evidence.investigation_id')
            ->where('investigations.tenant_id', $tenantId)
            ->groupBy('investigation_evidence.strength')
            ->selectRaw('investigation_evidence.strength AS strength, COUNT(*) AS count')
            ->pluck('count', 'strength')
            ->all();

        return [
            'strong'   => (int) ($rows['strong'] ?? 0),
            'moderate' => (int) ($rows['moderate'] ?? 0),
            // Evidence layer labels weak as its lowest tier; surfaced as "insufficient"
            'insufficient' => (int) ($rows['weak'] ?? 0),
        ];
    }

    public function actionQuality(int $tenantId): array
    {
        $base = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId));

        return [
            'completed'  => (clone $base)->where('status', Action::STATUS_COMPLETED)->count(),
            'cancelled'  => (clone $base)->where('status', Action::STATUS_CANCELLED)->count(),
            'in_progress'=> (clone $base)->where('status', Action::STATUS_IN_PROGRESS)->count(),
            'open'       => (clone $base)->whereIn('status', [Action::STATUS_UNASSIGNED, Action::STATUS_ASSIGNED, Action::STATUS_ACKNOWLEDGED])->count(),
            // Outcome-after-completion breakdown
            'completed_with_recovery' => InvestigationOutcome::where('tenant_id', $tenantId)
                ->whereIn('outcome_state', [InvestigationOutcome::STATE_OBSERVED_RECOVERY, InvestigationOutcome::STATE_PARTIAL_RECOVERY])
                ->count(),
        ];
    }

    /**
     * Per-rule performance from real detection + lifecycle events.
     */
    public function rulePerformance(int $tenantId): array
    {
        $rows = DB::table('anomalies')
            ->where('tenant_id', $tenantId)
            ->groupBy('rule_type')
            ->selectRaw('rule_type')
            ->selectRaw('COUNT(*) AS detections')
            ->selectRaw('COUNT(*) FILTER (WHERE dismissed_at IS NOT NULL) AS dismissals')
            ->selectRaw('COUNT(*) FILTER (WHERE is_false_positive) AS fp')
            ->selectRaw('COUNT(DISTINCT investigation_id) FILTER (WHERE investigation_id IS NOT NULL) AS investigations')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $label = AnomalySetting::RULES[$r->rule_type]['label'] ?? ucwords(str_replace('_', ' ', $r->rule_type));
            $out[] = [
                'rule_type'      => $r->rule_type,
                'label'          => $label,
                'detections'     => (int) $r->detections,
                'dismissals'     => (int) $r->dismissals,
                'false_positives'=> (int) $r->fp,
                'fp_rate'        => $this->pct((int) $r->fp, (int) $r->detections),
                'investigations' => (int) $r->investigations,
            ];
        }
        usort($out, fn ($a, $b) => $b['detections'] <=> $a['detections']);
        return $out;
    }

    /**
     * Noisy rules (high FP/dismissal) and noisy SKUs (most anomalies).
     */
    public function noisy(int $tenantId): array
    {
        $rules = collect($this->rulePerformance($tenantId))
            ->filter(fn ($r) => $r['detections'] >= 5 && $r['fp_rate'] !== null && $r['fp_rate'] >= 30)
            ->values()->all();

        $skus = DB::table('anomalies')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('sku')
            ->groupBy('sku')
            ->selectRaw('sku, COUNT(*) AS detections, COUNT(*) FILTER (WHERE is_false_positive) AS fp')
            ->orderByDesc('detections')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'sku'        => $r->sku,
                'detections' => (int) $r->detections,
                'fp'         => (int) $r->fp,
            ])->all();

        return ['rules' => $rules, 'skus' => $skus];
    }

    /**
     * Composite quality score — ONLY returned when statistically meaningful.
     * Formula is exposed so the number is never a black box.
     */
    public function overallScore(int $tenantId): ?array
    {
        $resolved = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])
            ->count();

        if ($resolved < self::MIN_SAMPLE_FOR_SCORE) {
            return [
                'available'   => false,
                'reason'      => 'Not enough resolved investigations yet (' . $resolved . '/' . self::MIN_SAMPLE_FOR_SCORE . ') for a statistically meaningful score.',
                'sample_size' => $resolved,
            ];
        }

        $rates = $this->rates($tenantId);
        $acceptance = $rates['acceptance_rate'] ?? 0;
        $lowFp      = 100 - ($rates['false_positive_rate'] ?? 0);
        $resolution = $rates['resolution_rate'] ?? 0;
        $actionComp = $rates['action_completion_rate'] ?? 0;

        // Equal-weighted, transparent
        $score = round(($acceptance + $lowFp + $resolution + $actionComp) / 4, 1);

        return [
            'available'   => true,
            'score'       => $score,
            'sample_size' => $resolved,
            'formula'     => 'mean(acceptance_rate, 100−false_positive_rate, resolution_rate, action_completion_rate)',
            'components'  => [
                'acceptance_rate'        => $acceptance,
                'low_false_positive'     => $lowFp,
                'resolution_rate'        => $resolution,
                'action_completion_rate' => $actionComp,
            ],
        ];
    }

    private function pct(int $numer, int $denom): ?float
    {
        if ($denom <= 0) {
            return null;
        }
        return round(($numer / $denom) * 100, 1);
    }
}
