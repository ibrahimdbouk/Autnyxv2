<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Action extends Model
{
    // ── Action types ──────────────────────────────────────────────────────────
    const TYPE_REORDER             = 'reorder';
    const TYPE_TRANSFER            = 'transfer';
    const TYPE_PRICE_ADJUSTMENT    = 'price_adjustment';
    const TYPE_SUPPLIER_CONTACT    = 'supplier_contact';
    const TYPE_WRITE_OFF           = 'write_off';
    const TYPE_DISCOUNT_REMOVAL    = 'discount_removal';
    const TYPE_MONITOR             = 'monitor';
    const TYPE_INVESTIGATE_FURTHER = 'investigate_further';
    const TYPE_OTHER               = 'other';

    // ── Status machine ────────────────────────────────────────────────────────
    const STATUS_UNASSIGNED   = 'unassigned';
    const STATUS_ASSIGNED     = 'assigned';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_IN_PROGRESS  = 'in_progress';
    const STATUS_BLOCKED      = 'blocked';
    const STATUS_COMPLETED    = 'completed';
    const STATUS_CANCELLED    = 'cancelled';

    // Legacy alias — keep for backward compat
    const STATUS_PENDING = 'unassigned';

    // ── Priority levels ───────────────────────────────────────────────────────
    const PRIORITY_CRITICAL = 'critical';
    const PRIORITY_HIGH     = 'high';
    const PRIORITY_MEDIUM   = 'medium';
    const PRIORITY_LOW      = 'low';

    // ── Escalation states ─────────────────────────────────────────────────────
    const ESCALATION_OVERDUE    = 'overdue';
    const ESCALATION_AT_RISK    = 'at_risk';
    const ESCALATION_ESCALATED  = 'escalated';

    // ── Human-readable labels ─────────────────────────────────────────────────
    const TYPE_LABELS = [
        self::TYPE_REORDER             => 'Replenishment',
        self::TYPE_TRANSFER            => 'Inventory Transfer',
        self::TYPE_PRICE_ADJUSTMENT    => 'Pricing',
        self::TYPE_SUPPLIER_CONTACT    => 'Supplier',
        self::TYPE_WRITE_OFF           => 'Write-Off',
        self::TYPE_DISCOUNT_REMOVAL    => 'Marketing',
        self::TYPE_MONITOR             => 'Monitor',
        self::TYPE_INVESTIGATE_FURTHER => 'Investigation',
        self::TYPE_OTHER               => 'Other',
    ];

    const STATUS_LABELS = [
        self::STATUS_UNASSIGNED   => 'Unassigned',
        self::STATUS_ASSIGNED     => 'Assigned',
        self::STATUS_ACKNOWLEDGED => 'Acknowledged',
        self::STATUS_IN_PROGRESS  => 'In Progress',
        self::STATUS_BLOCKED      => 'Blocked',
        self::STATUS_COMPLETED    => 'Completed',
        self::STATUS_CANCELLED    => 'Cancelled',
    ];

    const PRIORITY_LABELS = [
        self::PRIORITY_CRITICAL => 'Critical',
        self::PRIORITY_HIGH     => 'High',
        self::PRIORITY_MEDIUM   => 'Medium',
        self::PRIORITY_LOW      => 'Low',
    ];

    protected $fillable = [
        'investigation_id',
        'anomaly_id',
        'action_type',
        'title',
        'description',
        'notes',
        'completion_notes',
        'status',
        'priority',
        'escalation_state',
        'assigned_to',
        'assigned_team_id',
        'created_by',
        'due_at',
        'completed_at',
        'cancelled_at',
        'acknowledged_at',
    ];

    protected $casts = [
        'due_at'          => 'datetime',
        'completed_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function anomaly(): BelongsTo
    {
        return $this->belongsTo(Anomaly::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucwords(str_replace('_', ' ', $this->status));
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED    => 'success',
            self::STATUS_IN_PROGRESS  => 'info',
            self::STATUS_ACKNOWLEDGED => 'info',
            self::STATUS_BLOCKED      => 'danger',
            self::STATUS_CANCELLED    => 'gray',
            self::STATUS_ASSIGNED     => 'warning',
            default                   => 'gray',
        };
    }

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->action_type] ?? ucwords(str_replace('_', ' ', $this->action_type));
    }

    public function getPriorityLabel(): string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? ucfirst($this->priority ?? 'medium');
    }

    public function isOverdue(): bool
    {
        return $this->due_at
            && $this->due_at->isPast()
            && !in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    public function isActive(): bool
    {
        return !in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    public function getSlaStatus(): string
    {
        if (!$this->due_at || !$this->isActive()) {
            return 'none';
        }
        $hoursLeft = now()->diffInHours($this->due_at, false);
        if ($hoursLeft < 0)  return 'overdue';
        if ($hoursLeft < 4)  return 'critical';
        if ($hoursLeft < 24) return 'warning';
        return 'ok';
    }

    public function getSlaHoursRemaining(): ?int
    {
        if (!$this->due_at) return null;
        return (int) now()->diffInHours($this->due_at, false);
    }
}
