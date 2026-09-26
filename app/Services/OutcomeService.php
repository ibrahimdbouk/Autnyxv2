<?php

namespace App\Services;

use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use Illuminate\Support\Facades\DB;

/**
 * Records and updates financial outcomes for resolved/closed investigations.
 *
 * Revenue at risk comes from the AI narrator (estimated). Observed recovery
 * is entered by the analyst after resolution. This service keeps them in sync
 * and back-propagates observed_recovery to the parent investigations row for
 * quick dashboard access without a join.
 */
class OutcomeService
{
    /**
     * Record or update the outcome for an investigation.
     * Safe to call multiple times — upserts on investigation_id.
     */
    public function record(Investigation $investigation, array $data): InvestigationOutcome
    {
        return DB::transaction(function () use ($investigation, $data) {
            // W10: a recovery figure typed in by a person is a CLAIM until the
            // measurement confirms it (measured_recovery is never set from here).
            unset($data['measured_recovery']);
            $existing = InvestigationOutcome::where('investigation_id', $investigation->id)->first();
            if ((float) ($data['observed_recovery'] ?? 0) > 0 && ($existing?->measured_recovery === null)) {
                $data['attribution_status'] = InvestigationOutcome::ATTR_CLAIMED;
            }

            $payload = array_merge($data, [
                'investigation_id' => $investigation->id,
                'tenant_id'        => $investigation->tenant_id,
                // Snapshot the AI estimate at record time
                'revenue_at_risk'  => $data['revenue_at_risk'] ?? $investigation->revenue_at_risk,
                'recorded_by'      => $data['recorded_by'] ?? auth()->id(),
                'recorded_at'      => now(),
            ]);

            $outcome = InvestigationOutcome::updateOrCreate(
                ['investigation_id' => $investigation->id],
                $payload
            );

            // Back-propagate observed_recovery to investigations for dashboard queries
            if (isset($data['observed_recovery'])) {
                $investigation->update(['observed_recovery' => $data['observed_recovery']]);
            }

            // W11: a resolved outcome starts the measurement even when nobody
            // logged the fix as an action — the fix is recorded here, dated
            // when the person says recovery began (else now).
            $this->ensureMeasurableFix($investigation, $outcome);

            // If marked as false positive, send FP feedback to the detection engine
            if (! empty($data['was_false_positive']) && ! $outcome->rule_feedback_sent) {
                $this->sendFalsePositiveFeedback($investigation, $outcome);
            }

            AuditLogger::log(
                $investigation,
                \App\Models\AuditLog::EVENT_COMMENT_ADDED,
                'Outcome recorded: ' . ($outcome->getOutcomeTypeLabel()),
                auth()->id()
            );

            return $outcome->fresh();
        });
    }

    /**
     * Quick helper to set observed recovery only (e.g. from a dashboard input).
     */
    public function setObservedRecovery(Investigation $investigation, float $amount, ?string $method = null): InvestigationOutcome
    {
        return $this->record($investigation, [
            'observed_recovery' => $amount,
            'recovery_method'   => $method,
        ]);
    }

    /**
     * Tenant-level financial summary for dashboards.
     *
     * @return array{total_at_risk: float, total_recovered: float, recovery_rate: float|null, fp_count: int}
     */
    public function tenantSummary(int $tenantId): array
    {
        $row = InvestigationOutcome::where('tenant_id', $tenantId)
            ->selectRaw('
                COALESCE(SUM(revenue_at_risk), 0)    AS total_at_risk,
                COALESCE(SUM(measured_recovery) FILTER (WHERE measured_recovery > 0), 0) AS total_recovered,
                COALESCE(SUM(observed_recovery) FILTER (WHERE measured_recovery IS NULL AND observed_recovery > 0), 0) AS total_claimed,
                COUNT(*) FILTER (WHERE was_false_positive) AS fp_count
            ')
            ->first();

        // W10: "recovered" is measured recovery; hand-entered figures are claims.
        $atRisk    = (float) ($row->total_at_risk ?? 0);
        $recovered = (float) ($row->total_recovered ?? 0);

        return [
            'total_at_risk'  => $atRisk,
            'total_recovered' => $recovered,
            'total_claimed'  => (float) ($row->total_claimed ?? 0),
            'recovery_rate'  => $atRisk > 0 ? round(($recovered / $atRisk) * 100, 1) : null,
            'fp_count'       => (int) ($row->fp_count ?? 0),
        ];
    }

    /**
     * W11: measurement starts from a completed action. When an investigation
     * is resolved with an outcome and no action was ever completed on it, the
     * fix itself becomes that action ("fix recorded with the outcome").
     */
    public function ensureMeasurableFix(Investigation $investigation, InvestigationOutcome $outcome, ?\DateTimeInterface $at = null): ?\App\Models\Action
    {
        if ($outcome->outcome_type !== InvestigationOutcome::TYPE_RESOLVED || $outcome->was_false_positive) {
            return null;
        }
        if ($investigation->actions()->where('status', \App\Models\Action::STATUS_COMPLETED)->exists()) {
            return null;
        }
        $when = \Illuminate\Support\Carbon::parse($at ?? $outcome->recovery_measured_from ?? now());
        // Never before the investigation existed: a fix can't predate the finding it fixes.
        if ($investigation->opened_at && $when->lt($investigation->opened_at)) {
            $when = \Illuminate\Support\Carbon::parse($investigation->opened_at);
        }

        return \App\Models\Action::create([
            'investigation_id' => $investigation->id,
            'action_type'      => \App\Models\Action::TYPE_FIX_RECORDED,
            'title'            => 'Fix recorded with the outcome',
            'description'      => 'Created when the outcome was recorded, so the result can be measured from this date.'
                . ($outcome->recovery_notes ? ' Notes: ' . $outcome->recovery_notes : ''),
            'status'           => \App\Models\Action::STATUS_COMPLETED,
            'priority'         => \App\Models\Action::PRIORITY_MEDIUM,
            'created_by'       => $outcome->recorded_by,
            'completed_at'     => $when,
        ]);
    }

    /**
     * Send false-positive signal back to the baseline/detection engine.
     * Currently records on the anomaly; future: could adjust thresholds.
     */
    private function sendFalsePositiveFeedback(Investigation $investigation, InvestigationOutcome $outcome): void
    {
        try {
            // Mark all linked anomalies as false positives — an explicit
            // false-positive outcome, so it is feedback for the detector (WP4.3).
            $dismissal = app(\App\Services\Anomaly\AnomalyDismissal::class);
            $investigation->anomalies()->whereNull('dismissed_at')->get()
                ->each(fn ($a) => $dismissal->dismiss($a, \App\Services\Anomaly\AnomalyDismissal::REASON_FALSE_POSITIVE, auth()->user()));
            $investigation->anomalies()->update(['is_false_positive' => true]);

            $outcome->update(['rule_feedback_sent' => true]);
        } catch (\Throwable) {
            // Best-effort — do not break the outcome recording
        }
    }
}
