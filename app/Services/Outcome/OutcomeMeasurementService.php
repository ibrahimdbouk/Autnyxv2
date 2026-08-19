<?php

namespace App\Services\Outcome;

use App\Models\Action;
use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\OutcomeMeasurement;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OutcomeMeasurementService — Feature 8
 *
 * Deterministically measures business outcome after an action completes. Opens
 * a monitoring window keyed off the completed action, compares post-action
 * metrics against a pre-anomaly baseline and the depressed "during" level, and
 * assigns an explicit outcome state. Action completion never equals recovery —
 * recovery is only concluded when the metric materially recovers.
 *
 * All values are pure functions of stored sales data. AI is not involved.
 */
class OutcomeMeasurementService
{
    public const VERSION = 'v1';

    /** Post-action monitoring window length. */
    private const MONITORING_DAYS = 14;
    /** Minimum elapsed days before a conclusion beyond "monitoring". */
    private const MIN_ELAPSED_DAYS = 3;
    /** Fraction of baseline that counts as full recovery. */
    private const RECOVERY_THRESHOLD = 0.90;
    /** Fraction above the during-level that counts as partial recovery. */
    private const PARTIAL_UPLIFT = 0.10;

    /**
     * Measure all eligible investigations for a tenant. Returns count measured.
     */
    public function measureTenant(int $tenantId): int
    {
        // Candidates: have at least one completed action and are being resolved.
        $candidates = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [
                Investigation::STATUS_IN_PROGRESS,
                Investigation::STATUS_RESOLVED,
                Investigation::STATUS_CLOSED,
            ])
            ->whereHas('actions', fn ($q) => $q->where('status', Action::STATUS_COMPLETED))
            ->with(['outcome', 'anomalies'])
            ->get();

        $measured = 0;
        foreach ($candidates as $investigation) {
            // Skip if outcome already concluded with a terminal state, unless due for re-measure
            $outcome = $investigation->outcome;
            if ($outcome
                && in_array($outcome->outcome_state, [
                    InvestigationOutcome::STATE_OBSERVED_RECOVERY,
                    InvestigationOutcome::STATE_NO_MATERIAL_CHANGE,
                ], true)
                && $outcome->next_measurement_at === null) {
                continue;
            }

            if ($this->measureInvestigation($investigation)) {
                $measured++;
            }
        }

        return $measured;
    }

    /**
     * Measure a single investigation. Returns true if a measurement was recorded.
     */
    public function measureInvestigation(Investigation $investigation): bool
    {
        $sku = $investigation->primary_sku;

        $completedAction = $investigation->actions()
            ->where('status', Action::STATUS_COMPLETED)
            ->orderByDesc('completed_at')
            ->first();

        if (! $completedAction || ! $completedAction->completed_at) {
            return false;
        }

        // No SKU → cannot measure sales deterministically at this granularity.
        if (! $sku) {
            $this->writeOutcomeState($investigation, InvestigationOutcome::STATE_INSUFFICIENT_EVIDENCE, [
                'reason' => 'no_primary_sku',
            ], $completedAction);
            return false;
        }

        $completedAt = Carbon::parse($completedAction->completed_at);
        $detectedAt  = $investigation->anomalies->min('detected_at')
            ? Carbon::parse($investigation->anomalies->min('detected_at'))
            : Carbon::parse($investigation->opened_at ?? $completedAt);

        $windowStart = $completedAt->copy();
        $windowEnd   = $completedAt->copy()->addDays(self::MONITORING_DAYS);
        $now         = now();
        $measureTo   = $now->lt($windowEnd) ? $now : $windowEnd;

        $elapsedDays = max(1, $windowStart->diffInDays($measureTo));

        // Deterministic daily revenue helpers
        $baselineDaily = $this->dailyRevenue($investigation->tenant_id, $sku, $detectedAt->copy()->subDays(35), $detectedAt->copy()->subDays(8));
        $duringDaily   = $this->dailyRevenue($investigation->tenant_id, $sku, $detectedAt->copy()->subDays(7), $completedAt);
        $observedDaily = $this->dailyRevenue($investigation->tenant_id, $sku, $windowStart, $measureTo);

        $observedTotal = round($observedDaily * $elapsedDays, 2);
        $recoveryAmount = round(max(0, ($observedDaily - $duringDaily) * $elapsedDays), 2);
        $delta = round($observedDaily - $baselineDaily, 4);

        // Determine state
        $state = $this->deriveState($baselineDaily, $duringDaily, $observedDaily, $elapsedDays, $now->lt($windowEnd));

        $details = [
            'version'        => self::VERSION,
            'sku'            => $sku,
            'baseline_daily' => round($baselineDaily, 4),
            'during_daily'   => round($duringDaily, 4),
            'observed_daily' => round($observedDaily, 4),
            'elapsed_days'   => $elapsedDays,
            'window'         => [$windowStart->toDateString(), $windowEnd->toDateString()],
            'measured_to'    => $measureTo->toDateString(),
        ];

        // Append the measurement (reproducible)
        OutcomeMeasurement::create([
            'tenant_id'           => $investigation->tenant_id,
            'investigation_id'    => $investigation->id,
            'action_id'           => $completedAction->id,
            'metric_type'         => OutcomeMeasurement::METRIC_SALES_REVENUE,
            'baseline_value'      => round($baselineDaily, 4),
            'expected_value'      => round($baselineDaily, 4),
            'observed_value'      => round($observedDaily, 4),
            'delta_value'         => $delta,
            'recovery_amount'     => $recoveryAmount,
            'window_start'        => $windowStart->toDateString(),
            'window_end'          => $windowEnd->toDateString(),
            'outcome_state'       => $state,
            'calculation_version' => self::VERSION,
            'details'             => $details,
            'computed_at'         => now(),
        ]);

        // Attribution stays conservative and SEPARATE from observed recovery.
        $attribution = $state === OutcomeMeasurement::STATE_OBSERVED_RECOVERY
            ? InvestigationOutcome::ATTR_ESTIMATED
            : InvestigationOutcome::ATTR_NOT_ATTEMPTED;
        $evidenceStrength = $state === OutcomeMeasurement::STATE_OBSERVED_RECOVERY ? 'moderate' : 'insufficient';

        $stillMonitoring = $now->lt($windowEnd);

        $payload = [
            'outcome_state'            => $state,
            'attribution_status'       => $attribution,
            'attribution_method'       => 'single_metric_sales_revenue',
            'evidence_strength'        => $evidenceStrength,
            'measurement_window_start' => $windowStart->toDateString(),
            'measurement_window_end'   => $windowEnd->toDateString(),
            'baseline_json'            => ['daily_revenue' => round($baselineDaily, 4)],
            'metrics_json'             => $details + ['recovery_amount' => $recoveryAmount],
            'calculation_version'      => self::VERSION,
            'monitoring_started_at'    => $windowStart,
            'next_measurement_at'      => $stillMonitoring ? $now->copy()->addDay() : null,
        ];

        // Do not clobber an analyst-entered observed_recovery; only fill if empty.
        $existing = $investigation->outcome;
        if (! $existing || $existing->observed_recovery === null) {
            if ($state === OutcomeMeasurement::STATE_OBSERVED_RECOVERY
                || $state === OutcomeMeasurement::STATE_PARTIAL_RECOVERY) {
                $payload['observed_recovery'] = $recoveryAmount;
            }
        }

        $this->upsertOutcome($investigation, $payload);

        AuditLogger::log(
            $investigation,
            AuditLog::EVENT_OUTCOME_MEASURED,
            'Outcome measured: ' . str_replace('_', ' ', $state)
                . ' (observed ' . number_format($observedTotal, 2) . ')',
            null
        );

        return true;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function deriveState(
        float $baselineDaily,
        float $duringDaily,
        float $observedDaily,
        int $elapsedDays,
        bool $stillMonitoring
    ): string {
        if ($baselineDaily <= 0 && $observedDaily <= 0) {
            return OutcomeMeasurement::STATE_INSUFFICIENT_EVIDENCE;
        }
        if ($elapsedDays < self::MIN_ELAPSED_DAYS && $stillMonitoring) {
            return OutcomeMeasurement::STATE_MONITORING;
        }

        // Full recovery: back to ~normal
        if ($baselineDaily > 0 && $observedDaily >= $baselineDaily * self::RECOVERY_THRESHOLD) {
            return OutcomeMeasurement::STATE_OBSERVED_RECOVERY;
        }

        // Partial: materially above the depressed level but not fully recovered
        if ($duringDaily >= 0 && $observedDaily > $duringDaily * (1 + self::PARTIAL_UPLIFT)
            && $observedDaily > $duringDaily) {
            return $stillMonitoring
                ? OutcomeMeasurement::STATE_MONITORING
                : OutcomeMeasurement::STATE_PARTIAL_RECOVERY;
        }

        // No material movement
        return $stillMonitoring
            ? OutcomeMeasurement::STATE_MONITORING
            : OutcomeMeasurement::STATE_NO_MATERIAL_CHANGE;
    }

    /**
     * Average daily revenue for a SKU over [from, to] (inclusive).
     */
    private function dailyRevenue(int $tenantId, string $sku, Carbon $from, Carbon $to): float
    {
        if ($to->lte($from)) {
            return 0.0;
        }

        $total = (float) DB::table('sales_transactions')
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum(DB::raw('COALESCE(total_amount, quantity * COALESCE(unit_price, 0))'));

        $days = max(1, $from->diffInDays($to));
        return round($total / $days, 4);
    }

    private function upsertOutcome(Investigation $investigation, array $payload): void
    {
        InvestigationOutcome::updateOrCreate(
            ['investigation_id' => $investigation->id],
            $payload + [
                'tenant_id'       => $investigation->tenant_id,
                'revenue_at_risk' => $investigation->revenue_at_risk,
                'recorded_at'     => now(),
            ]
        );

        if (isset($payload['observed_recovery'])) {
            $investigation->update(['observed_recovery' => $payload['observed_recovery']]);
        }
    }

    private function writeOutcomeState(Investigation $investigation, string $state, array $metrics, ?Action $action): void
    {
        InvestigationOutcome::updateOrCreate(
            ['investigation_id' => $investigation->id],
            [
                'tenant_id'           => $investigation->tenant_id,
                'outcome_state'       => $state,
                'metrics_json'        => $metrics,
                'calculation_version' => self::VERSION,
                'recorded_at'         => now(),
            ]
        );
    }
}
