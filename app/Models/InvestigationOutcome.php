<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationOutcome extends Model
{
    // Outcome types
    const TYPE_RESOLVED            = 'resolved';
    const TYPE_FALSE_POSITIVE      = 'false_positive';
    const TYPE_DUPLICATE           = 'duplicate';
    const TYPE_ESCALATED_TO_OPS    = 'escalated_to_ops';
    const TYPE_NO_ACTION_NEEDED    = 'no_action_needed';

    const TYPE_LABELS = [
        self::TYPE_RESOLVED          => 'Resolved',
        self::TYPE_FALSE_POSITIVE    => 'False Positive',
        self::TYPE_DUPLICATE         => 'Duplicate',
        self::TYPE_ESCALATED_TO_OPS  => 'Escalated to Ops',
        self::TYPE_NO_ACTION_NEEDED  => 'No Action Needed',
    ];

    // Recovery methods
    const RECOVERY_SALES_REBOUND     = 'sales_rebound';
    const RECOVERY_STOCKOUT_CLEARED  = 'stockout_cleared';
    const RECOVERY_RETURN_RATE_DROP  = 'return_rate_drop';
    const RECOVERY_MANUAL_ESTIMATE   = 'manual_estimate';

    const RECOVERY_METHOD_LABELS = [
        self::RECOVERY_SALES_REBOUND    => 'Sales Rebound',
        self::RECOVERY_STOCKOUT_CLEARED => 'Stockout Cleared',
        self::RECOVERY_RETURN_RATE_DROP => 'Return Rate Drop',
        self::RECOVERY_MANUAL_ESTIMATE  => 'Manual Estimate',
    ];

    // ── Outcome states (M23 / Feature 8) ────────────────────────────────────
    const STATE_NOT_MEASURED          = 'not_measured';
    const STATE_MONITORING            = 'monitoring';
    const STATE_NO_MATERIAL_CHANGE    = 'no_material_change';
    const STATE_PARTIAL_RECOVERY      = 'partial_recovery';
    const STATE_OBSERVED_RECOVERY     = 'observed_recovery';
    const STATE_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    const STATE_LABELS = [
        self::STATE_NOT_MEASURED          => 'Not Measured',
        self::STATE_MONITORING            => 'Monitoring',
        self::STATE_NO_MATERIAL_CHANGE    => 'No Material Change',
        self::STATE_PARTIAL_RECOVERY      => 'Partial Recovery',
        self::STATE_OBSERVED_RECOVERY     => 'Observed Recovery',
        self::STATE_INSUFFICIENT_EVIDENCE => 'Insufficient Evidence',
    ];

    // ── Attribution states (kept separate from observed recovery) ────────────
    const ATTR_NOT_ATTEMPTED        = 'not_attempted';
    const ATTR_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';
    const ATTR_ESTIMATED            = 'estimated';
    const ATTR_HIGH_CONFIDENCE      = 'high_confidence';

    const ATTR_LABELS = [
        self::ATTR_NOT_ATTEMPTED         => 'Not Attempted',
        self::ATTR_INSUFFICIENT_EVIDENCE => 'Insufficient Evidence',
        self::ATTR_ESTIMATED             => 'Estimated',
        self::ATTR_HIGH_CONFIDENCE       => 'High Confidence',
    ];

    protected $fillable = [
        'investigation_id',
        'tenant_id',
        'revenue_at_risk',
        'observed_recovery',
        'cost_to_resolve',
        'recovery_method',
        'recovery_measured_from',
        'recovery_measured_to',
        'recovery_notes',
        'outcome_type',
        'was_false_positive',
        'rule_feedback_sent',
        'confirmed_root_cause',
        'ai_root_cause_correct',
        'recorded_by',
        'recorded_at',
        // M23 / Feature 8 — deterministic measurement layer
        'outcome_state',
        'attribution_status',
        'attribution_method',
        'evidence_strength',
        'measurement_window_start',
        'measurement_window_end',
        'baseline_json',
        'metrics_json',
        'calculation_version',
        'monitoring_started_at',
        'next_measurement_at',
    ];

    protected $casts = [
        'revenue_at_risk'        => 'decimal:2',
        'observed_recovery'      => 'decimal:2',
        'cost_to_resolve'        => 'decimal:2',
        'was_false_positive'     => 'boolean',
        'rule_feedback_sent'     => 'boolean',
        'ai_root_cause_correct'  => 'boolean',
        'recovery_measured_from' => 'date',
        'recovery_measured_to'   => 'date',
        'recorded_at'            => 'datetime',
        'measurement_window_start' => 'date',
        'measurement_window_end'   => 'date',
        'baseline_json'          => 'array',
        'metrics_json'           => 'array',
        'monitoring_started_at'  => 'datetime',
        'next_measurement_at'    => 'datetime',
    ];

    // ── Relations ──────────────────────────────────────────────────────────

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function getOutcomeTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->outcome_type] ?? ucfirst($this->outcome_type);
    }

    public function getRecoveryMethodLabel(): string
    {
        return self::RECOVERY_METHOD_LABELS[$this->recovery_method] ?? ucfirst($this->recovery_method ?? '—');
    }

    public function getOutcomeStateLabel(): string
    {
        return self::STATE_LABELS[$this->outcome_state] ?? ucwords(str_replace('_', ' ', (string) $this->outcome_state));
    }

    public function getOutcomeStateColor(): string
    {
        return match ($this->outcome_state) {
            self::STATE_OBSERVED_RECOVERY     => 'success',
            self::STATE_PARTIAL_RECOVERY      => 'info',
            self::STATE_MONITORING            => 'warning',
            self::STATE_NO_MATERIAL_CHANGE    => 'gray',
            self::STATE_INSUFFICIENT_EVIDENCE => 'gray',
            default                           => 'gray',
        };
    }

    public function getAttributionLabel(): string
    {
        return self::ATTR_LABELS[$this->attribution_status] ?? ucwords(str_replace('_', ' ', (string) $this->attribution_status));
    }

    /**
     * Net financial impact: observed_recovery minus cost_to_resolve.
     */
    public function getNetImpact(): ?float
    {
        if ($this->observed_recovery === null) {
            return null;
        }
        return (float) $this->observed_recovery - (float) ($this->cost_to_resolve ?? 0);
    }

    /**
     * Recovery rate: observed / revenue_at_risk as a percentage.
     */
    public function getRecoveryRate(): ?float
    {
        if (! $this->revenue_at_risk || ! $this->observed_recovery) {
            return null;
        }
        return round(($this->observed_recovery / $this->revenue_at_risk) * 100, 1);
    }

    protected static function booted(): void
    {
        parent::booted();

        // P1.2 — mirror each recorded outcome into the event backbone.
        // Best-effort: capture must never break outcome recording.
        static::created(function (self $outcome): void {
            try {
                if (! $outcome->tenant_id) {
                    return;
                }
                app(\App\Services\Platform\EventStore::class)->append([
                    'tenant_id'   => $outcome->tenant_id,
                    'event_type'  => \App\Models\PlatformEvent::TYPE_OUTCOME,
                    'occurred_at' => $outcome->recorded_at ?? now(),
                    'source'      => 'recovery',
                    'source_ref'  => 'outcome:' . $outcome->id,
                    'value'       => $outcome->observed_recovery,
                    'payload'     => [
                        'outcome_id'        => $outcome->id,
                        'outcome_type'      => $outcome->outcome_type,
                        'revenue_at_risk'   => $outcome->revenue_at_risk,
                        'observed_recovery' => $outcome->observed_recovery,
                        'recovery_method'   => $outcome->recovery_method,
                        'investigation_id'  => $outcome->investigation_id,
                    ],
                ]);
            } catch (\Throwable $e) {
                // best-effort
            }
        });
    }
}
