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
                COALESCE(SUM(observed_recovery), 0)  AS total_recovered,
                COUNT(*) FILTER (WHERE was_false_positive) AS fp_count
            ')
            ->first();

        $atRisk    = (float) ($row->total_at_risk ?? 0);
        $recovered = (float) ($row->total_recovered ?? 0);

        return [
            'total_at_risk'  => $atRisk,
            'total_recovered' => $recovered,
            'recovery_rate'  => $atRisk > 0 ? round(($recovered / $atRisk) * 100, 1) : null,
            'fp_count'       => (int) ($row->fp_count ?? 0),
        ];
    }

    /**
     * Send false-positive signal back to the baseline/detection engine.
     * Currently records on the anomaly; future: could adjust thresholds.
     */
    private function sendFalsePositiveFeedback(Investigation $investigation, InvestigationOutcome $outcome): void
    {
        try {
            // Mark all linked anomalies as false positives
            $investigation->anomalies()->update([
                'is_false_positive' => true,
                'dismissed_at'      => now(),
            ]);

            $outcome->update(['rule_feedback_sent' => true]);
        } catch (\Throwable) {
            // Best-effort — do not break the outcome recording
        }
    }
}
