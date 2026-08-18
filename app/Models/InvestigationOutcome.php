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
}
