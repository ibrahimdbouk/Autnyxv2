<?php

namespace App\Services\Outcome;

use App\Models\Action;
use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\OutcomeMeasurement;
use App\Services\AuditLogger;
use App\Support\Detection\ValueModel;
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
        if (\App\Services\Anomaly\AnomalyDetectionService::rulesV2For((int) $investigation->tenant_id)) {
            return $this->measureInvestigationV2($investigation);
        }

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
            // W10: what the measurement itself found (0 = no recovery seen).
            'measured_recovery'        => in_array($state, [OutcomeMeasurement::STATE_OBSERVED_RECOVERY, OutcomeMeasurement::STATE_PARTIAL_RECOVERY], true)
                ? $recoveryAmount : 0,
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

    // ── v2 (WP4.4 / audit H21) ────────────────────────────────────────────────

    public const VERSION_V2 = 'v2';

    /** Days after the action at which the outcome is measured — one row each. */
    public const CHECKPOINTS = [3, 7, 14];

    /**
     * v2 measurement, for tenants on the corrected rules:
     *
     *   • the metric follows the money: lost-revenue investigations are read on
     *     revenue, capital investigations on stock value on hand — both scoped
     *     to the investigation's store when it has one;
     *   • a counterfactual: what the rest of the business did over the same
     *     days scales the expectation, so a chain-wide lift isn't claimed as
     *     this action's recovery (and a chain-wide dip doesn't hide one);
     *   • one measurement per checkpoint (day 3, 7, 14 of DATA after the
     *     action), not a row a day; a checkpoint the sales feed hasn't reached
     *     yet waits;
     *   • only lost-revenue recovery is written to observed_recovery, so the
     *     recovery rate is on the same basis as revenue at risk; released
     *     capital is reported in the metrics.
     */
    public function measureInvestigationV2(Investigation $investigation): bool
    {
        $action = $investigation->actions()->where('status', Action::STATUS_COMPLETED)
            ->orderByDesc('completed_at')->first();
        if (! $action || ! $action->completed_at) {
            return false; // no action taken → nothing to attribute
        }

        $members = $investigation->anomalies()->get(['id', 'rule_type', 'sku', 'store_id', 'context', 'value_type', 'detected_at']);
        $type = $this->dominantType($members);
        $sku  = $investigation->primary_sku ?: $members->whereNotNull('sku')->first()?->sku;
        $storeId = $investigation->primary_store_id;

        if (! $sku || ! in_array($type, [ValueModel::LOST_REVENUE, ValueModel::CAPITAL], true)) {
            $this->writeOutcomeState($investigation, InvestigationOutcome::STATE_INSUFFICIENT_EVIDENCE, [
                'reason' => ! $sku ? 'no_primary_sku' : 'no_measurable_money_metric', 'version' => self::VERSION_V2,
            ], $action);

            return false;
        }

        $tenantId  = (int) $investigation->tenant_id;
        $done      = Carbon::parse($action->completed_at)->startOfDay();
        $detected  = Carbon::parse($members->min('detected_at') ?? $investigation->opened_at ?? $done)->startOfDay();
        $dataClock = DB::table('sales_daily')->where('tenant_id', $tenantId)->max('date');
        $dataTo    = $dataClock ? Carbon::parse($dataClock)->startOfDay()->addDay() : $done; // exclusive
        $elapsed   = $dataTo->gt($done) ? (int) $done->diffInDays($dataTo, absolute: true) : 0;

        $reached = array_values(array_filter(self::CHECKPOINTS, fn ($c) => $c <= $elapsed));
        $next    = collect(self::CHECKPOINTS)->first(fn ($c) => $c > $elapsed);
        if ($reached === []) {
            $this->upsertOutcome($investigation, [
                'outcome_state'       => InvestigationOutcome::STATE_MONITORING,
                'calculation_version' => self::VERSION_V2,
                'monitoring_started_at' => $done,
                'next_measurement_at' => $done->copy()->addDays($next ?? 3),
            ]);

            return false;
        }
        $checkpoint = end($reached);

        $already = OutcomeMeasurement::where('investigation_id', $investigation->id)->where('action_id', $action->id)
            ->where('calculation_version', self::VERSION_V2)->get()
            ->contains(fn ($m) => (int) ($m->details['checkpoint'] ?? 0) === $checkpoint);
        if ($already) {
            return false;
        }

        $final = $checkpoint === max(self::CHECKPOINTS);
        $obsFrom = $done;
        $obsTo   = $done->copy()->addDays($checkpoint);
        $metrics = $type === ValueModel::LOST_REVENUE
            ? $this->revenueOutcome($tenantId, $sku, $storeId, $detected, $done, $obsFrom, $obsTo, $final)
            : $this->capitalOutcome($tenantId, $sku, $storeId, $detected, $final);
        $state = $metrics['state'];
        unset($metrics['state']);

        $details = $metrics + [
            'version' => self::VERSION_V2, 'checkpoint' => $checkpoint, 'value_type' => $type,
            'sku' => $sku, 'store_id' => $storeId, 'window' => [$obsFrom->toDateString(), $obsTo->copy()->subDay()->toDateString()],
        ];

        OutcomeMeasurement::create([
            'tenant_id'           => $tenantId,
            'investigation_id'    => $investigation->id,
            'action_id'           => $action->id,
            'metric_type'         => $type === ValueModel::LOST_REVENUE ? OutcomeMeasurement::METRIC_SALES_REVENUE : 'stock_value',
            'baseline_value'      => $metrics['baseline'],
            'expected_value'      => $metrics['expected'],
            'observed_value'      => $metrics['observed'],
            'delta_value'         => round($metrics['observed'] - $metrics['expected'], 4),
            'recovery_amount'     => $metrics['recovered'],
            'window_start'        => $obsFrom->toDateString(),
            'window_end'          => $obsTo->toDateString(),
            'outcome_state'       => $state,
            'calculation_version' => self::VERSION_V2,
            'details'             => $details,
            'computed_at'         => now(),
        ]);

        $recovered = in_array($state, [OutcomeMeasurement::STATE_OBSERVED_RECOVERY, OutcomeMeasurement::STATE_PARTIAL_RECOVERY], true);
        $payload = [
            'outcome_state'            => $state,
            // An action was taken (required above), so attribution is estimated when recovery is seen.
            'attribution_status'       => $recovered ? InvestigationOutcome::ATTR_ESTIMATED : InvestigationOutcome::ATTR_NOT_ATTEMPTED,
            'attribution_method'       => $type === ValueModel::LOST_REVENUE ? 'revenue_vs_counterfactual' : 'stock_value_released',
            'evidence_strength'        => $recovered ? ($final ? 'moderate' : 'early') : 'insufficient',
            'measurement_window_start' => $obsFrom->toDateString(),
            'measurement_window_end'   => $done->copy()->addDays(max(self::CHECKPOINTS))->toDateString(),
            'baseline_json'            => ['baseline' => $metrics['baseline'], 'value_type' => $type],
            'metrics_json'             => $details,
            'calculation_version'      => self::VERSION_V2,
            'monitoring_started_at'    => $done,
            'next_measurement_at'      => $next !== null ? $done->copy()->addDays($next) : null,
        ];
        // W10: revenue recovery measured against the counterfactual (capital released is not revenue).
        if ($type === ValueModel::LOST_REVENUE) {
            $payload['measured_recovery'] = $recovered ? $metrics['recovered'] : 0;
        } else {
            // W11: stock value released, measured — reported beside recovery, never added to it.
            $payload['measured_capital'] = $recovered ? (float) ($metrics['capital_released'] ?? 0) : 0;
        }
        $existing = $investigation->outcome()->first();
        if ($type === ValueModel::LOST_REVENUE && $recovered && (! $existing || $existing->observed_recovery === null
                || ($existing->calculation_version === self::VERSION_V2))) {
            $payload['observed_recovery'] = $metrics['recovered'];
        }
        $this->upsertOutcome($investigation, $payload);

        AuditLogger::log($investigation, AuditLog::EVENT_OUTCOME_MEASURED,
            "Outcome measured at day {$checkpoint}: " . str_replace('_', ' ', $state), null);

        return true;
    }

    /** The value type carrying the most money among the members (lost revenue wins ties). */
    private function dominantType($members): ?string
    {
        $sum = [];
        foreach ($members as $a) {
            $ctx = is_array($a->context) ? $a->context : [];
            $t = $a->value_type ?: ValueModel::type((string) $a->rule_type, $ctx);
            $sum[$t] = ($sum[$t] ?? 0) + ValueModel::amount($ctx);
        }
        if ($sum === []) {
            return null;
        }
        arsort($sum);
        $top = array_key_first($sum);

        return isset($sum[ValueModel::LOST_REVENUE]) && $sum[ValueModel::LOST_REVENUE] >= $sum[$top] ? ValueModel::LOST_REVENUE : $top;
    }

    /** Average daily revenue from sales_daily over [from, to), optionally excluding a SKU. */
    private function dailyRevenueV2(int $tenantId, ?string $sku, ?int $storeId, Carbon $from, Carbon $to, bool $excludeSku = false): float
    {
        $days = max(1, (int) $from->diffInDays($to, absolute: true));
        $q = DB::table('sales_daily')->where('tenant_id', $tenantId)
            ->where('date', '>=', $from->toDateString())->where('date', '<', $to->toDateString());
        if ($sku !== null) {
            $excludeSku ? $q->where('sku', '!=', $sku) : $q->where('sku', $sku);
        }
        if ($storeId !== null && ! $excludeSku) {
            $q->where('store_id', $storeId);
        }

        return (float) $q->sum('revenue') / $days;
    }

    private function revenueOutcome(int $tenantId, string $sku, ?int $storeId, Carbon $detected, Carbon $done, Carbon $obsFrom, Carbon $obsTo, bool $final): array
    {
        $baseFrom = $detected->copy()->subDays(35);
        $baseTo   = $detected->copy()->subDays(7);
        $durFrom  = $detected->copy()->subDays(7);

        $baseline = $this->dailyRevenueV2($tenantId, $sku, $storeId, $baseFrom, $baseTo);
        $during   = $this->dailyRevenueV2($tenantId, $sku, $storeId, $durFrom, $done->gt($durFrom) ? $done : $detected);
        $observed = $this->dailyRevenueV2($tenantId, $sku, $storeId, $obsFrom, $obsTo);

        // Counterfactual: the rest of the business over the same days.
        $restBase = $this->dailyRevenueV2($tenantId, $sku, null, $baseFrom, $baseTo, true);
        $restObs  = $this->dailyRevenueV2($tenantId, $sku, null, $obsFrom, $obsTo, true);
        $factor   = $restBase > 0 && $restObs > 0 ? $restObs / $restBase : 1.0;

        $expected    = $baseline * $factor;     // where it should be, given the market
        $depressed   = $during * $factor;       // where it would be, had nothing changed
        $days        = max(1, (int) $obsFrom->diffInDays($obsTo, absolute: true));
        $recovered   = round(max(0.0, min($observed, $expected) - $depressed) * $days, 2);

        if ($baseline <= 0 && $observed <= 0) {
            $state = OutcomeMeasurement::STATE_INSUFFICIENT_EVIDENCE;
        } elseif ($expected > 0 && $observed >= $expected * self::RECOVERY_THRESHOLD) {
            $state = OutcomeMeasurement::STATE_OBSERVED_RECOVERY;
        } elseif ($observed > $depressed * (1 + self::PARTIAL_UPLIFT) && $observed > $depressed) {
            $state = $final ? OutcomeMeasurement::STATE_PARTIAL_RECOVERY : OutcomeMeasurement::STATE_MONITORING;
        } else {
            $state = $final ? OutcomeMeasurement::STATE_NO_MATERIAL_CHANGE : OutcomeMeasurement::STATE_MONITORING;
        }

        return [
            'state' => $state, 'baseline' => round($baseline, 4), 'expected' => round($expected, 4),
            'observed' => round($observed, 4), 'during' => round($during, 4), 'market_factor' => round($factor, 4),
            'recovered' => $state === OutcomeMeasurement::STATE_MONITORING ? 0.0 : $recovered,
        ];
    }

    /** Stock value on hand (latest snapshot, lots summed) now vs when the problem was detected. */
    private function capitalOutcome(int $tenantId, string $sku, ?int $storeId, Carbon $detected, bool $final): array
    {
        $cost = (float) (DB::table('products')->where('tenant_id', $tenantId)->where('sku', $sku)->value('unit_cost') ?? 0);
        $qtyAt = function (?Carbon $onOrBefore) use ($tenantId, $sku, $storeId) {
            $q = DB::table('inventory_levels')->where('tenant_id', $tenantId)->where('sku', $sku)
                ->when($storeId !== null, fn ($w) => $w->where('store_id', $storeId))
                ->when($onOrBefore !== null, fn ($w) => $w->where('as_of_date', '<=', $onOrBefore->toDateString()));
            $day = (clone $q)->max('as_of_date');

            return $day ? (float) (clone $q)->where('as_of_date', $day)->sum('on_hand_qty') : null;
        };
        $before = $qtyAt($detected);
        $now    = $qtyAt(null);

        if ($before === null || $now === null || $before <= 0) {
            return ['state' => OutcomeMeasurement::STATE_INSUFFICIENT_EVIDENCE, 'baseline' => 0.0, 'expected' => 0.0,
                'observed' => 0.0, 'recovered' => 0.0, 'capital_released' => 0.0];
        }
        $ratio = $now / $before;
        $state = $ratio <= 0.5 ? OutcomeMeasurement::STATE_OBSERVED_RECOVERY
            : ($ratio <= 0.9 ? ($final ? OutcomeMeasurement::STATE_PARTIAL_RECOVERY : OutcomeMeasurement::STATE_MONITORING)
                : ($final ? OutcomeMeasurement::STATE_NO_MATERIAL_CHANGE : OutcomeMeasurement::STATE_MONITORING));

        return [
            'state' => $state, 'baseline' => round($before * $cost, 2), 'expected' => round($before * $cost, 2),
            'observed' => round($now * $cost, 2), 'recovered' => 0.0,
            'capital_released' => round(max(0.0, $before - $now) * $cost, 2), 'unit_cost' => $cost,
        ];
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
