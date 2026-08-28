<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    // Event types
    const EVENT_STATUS_CHANGED     = 'status_changed';
    const EVENT_ASSIGNED           = 'assigned';
    const EVENT_REASSIGNED         = 'reassigned';
    const EVENT_ESCALATED          = 'escalated';
    const EVENT_ACTION_CREATED     = 'action_created';
    const EVENT_ACTION_COMPLETED   = 'action_completed';
    const EVENT_ACTION_CANCELLED   = 'action_cancelled';
    const EVENT_EVIDENCE_ADDED     = 'evidence_added';
    const EVENT_COMMENT_ADDED      = 'comment_added';
    const EVENT_AI_GENERATED       = 'ai_generated';
    const EVENT_FP_DISMISSED       = 'fp_dismissed';
    const EVENT_PRIORITY_CHANGED   = 'priority_changed';
    // M23
    const EVENT_WATCH_STARTED      = 'watch_started';
    const EVENT_WATCH_ENDED        = 'watch_ended';
    const EVENT_SNOOZED            = 'snoozed';
    const EVENT_UNSNOOZED          = 'unsnoozed';
    const EVENT_SUPPRESSED         = 'suppressed';
    const EVENT_SUPPRESSION_ENDED  = 'suppression_ended';
    const EVENT_BULK_ACTION        = 'bulk_action';
    const EVENT_OUTCOME_MEASURED   = 'outcome_measured';
    // 1a — access control (SOC 2 / ISO 27001: log permission changes)
    const EVENT_SCREEN_ACCESS_CHANGED = 'screen_access_changed';

    // Append-only — no updated_at
    public $timestamps  = false;
    const  CREATED_AT   = 'created_at';

    protected $fillable = [
        'tenant_id',
        'investigation_id',
        'anomaly_id',
        'action_id',
        'user_id',
        'event_type',
        'description',
        'old_value',
        'new_value',
        'created_at',
    ];

    protected $casts = [
        'old_value'  => 'array',
        'new_value'  => 'array',
        'created_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function anomaly(): BelongsTo
    {
        return $this->belongsTo(Anomaly::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getActorLabel(): string
    {
        return $this->user?->name ?? 'System';
    }

    public function getEventIcon(): string
    {
        return match ($this->event_type) {
            self::EVENT_STATUS_CHANGED   => 'heroicon-o-arrow-path',
            self::EVENT_ASSIGNED,
            self::EVENT_REASSIGNED       => 'heroicon-o-user',
            self::EVENT_ESCALATED        => 'heroicon-o-arrow-trending-up',
            self::EVENT_ACTION_CREATED   => 'heroicon-o-plus-circle',
            self::EVENT_ACTION_COMPLETED => 'heroicon-o-check-circle',
            self::EVENT_AI_GENERATED     => 'heroicon-o-sparkles',
            self::EVENT_FP_DISMISSED     => 'heroicon-o-x-circle',
            default                      => 'heroicon-o-information-circle',
        };
    }
}
