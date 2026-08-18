<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Anomaly extends Model
{
    // ── Severity ──────────────────────────────────────────────────────────────
    const SEVERITY_LOW    = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH   = 'high';

    // ── Investigation state machine ───────────────────────────────────────────
    const STATUS_DETECTED          = 'detected';
    const STATUS_INVESTIGATING     = 'investigating';
    const STATUS_CAUSE_ESTABLISHED = 'cause_established';
    const STATUS_ACTION_TAKEN      = 'action_taken';
    const STATUS_RESOLVED          = 'resolved';
    const STATUS_UNRESOLVED        = 'unresolved';

    // ── Confidence tiers ──────────────────────────────────────────────────────
    const CONFIDENCE_ESTABLISHED = 'established';
    const CONFIDENCE_PROBABLE    = 'probable';
    const CONFIDENCE_SUSPECTED   = 'suspected';
    const CONFIDENCE_UNKNOWN     = 'unknown';

    // ── Evidence gates ────────────────────────────────────────────────────────
    const GATE_ACT         = 'act';
    const GATE_INVESTIGATE = 'investigate';
    const GATE_MONITOR     = 'monitor';

    protected $fillable = [
        'tenant_id',
        'rule_type',
        'severity',
        'sku',
        'store_id',
        'product_id',
        'description',
        'context',
        'detected_at',
        'dismissed_at',
        'dismissed_by',
        'notified_at',
        // Investigation fields
        'investigation_status',
        'ai_what',
        'ai_why',
        'ai_confidence',
        'ai_how_big',
        'ai_trajectory',
        'ai_action',
        'ai_recommendation_gate',
        'ai_outcome',
        'ai_pattern',
        'ai_is_recurring',
        'ai_related_anomaly_ids',
        'ai_related_summary',
        'ai_generated_at',
        'action_taken_at',
        'action_notes',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'context'               => 'array',
        'ai_related_anomaly_ids'=> 'array',
        'ai_is_recurring'       => 'boolean',
        'detected_at'           => 'datetime',
        'dismissed_at'          => 'datetime',
        'ai_generated_at'       => 'datetime',
        'action_taken_at'       => 'datetime',
        'resolved_at'           => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function dismissedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    public function isInvestigated(): bool
    {
        return $this->ai_generated_at !== null;
    }

    public function getRuleLabel(): string
    {
        return AnomalySetting::RULES[$this->rule_type]['label'] ?? $this->rule_type;
    }

    public function getConfidenceLabel(): string
    {
        return match ($this->ai_confidence) {
            self::CONFIDENCE_ESTABLISHED => 'Established',
            self::CONFIDENCE_PROBABLE    => 'Probable',
            self::CONFIDENCE_SUSPECTED   => 'Suspected',
            default                      => 'Unknown',
        };
    }

    public function getConfidenceColor(): string
    {
        return match ($this->ai_confidence) {
            self::CONFIDENCE_ESTABLISHED => 'success',
            self::CONFIDENCE_PROBABLE    => 'info',
            self::CONFIDENCE_SUSPECTED   => 'warning',
            default                      => 'gray',
        };
    }

    public function getGateLabel(): string
    {
        return match ($this->ai_recommendation_gate) {
            self::GATE_ACT         => 'Act Now',
            self::GATE_INVESTIGATE => 'Investigate',
            default                => 'Monitor',
        };
    }

    public function getGateColor(): string
    {
        return match ($this->ai_recommendation_gate) {
            self::GATE_ACT         => 'danger',
            self::GATE_INVESTIGATE => 'warning',
            default                => 'info',
        };
    }
}
