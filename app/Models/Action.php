<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Action extends Model
{
    // Action types
    const TYPE_REORDER            = 'reorder';
    const TYPE_TRANSFER           = 'transfer';
    const TYPE_PRICE_ADJUSTMENT   = 'price_adjustment';
    const TYPE_SUPPLIER_CONTACT   = 'supplier_contact';
    const TYPE_WRITE_OFF          = 'write_off';
    const TYPE_DISCOUNT_REMOVAL   = 'discount_removal';
    const TYPE_MONITOR            = 'monitor';
    const TYPE_INVESTIGATE_FURTHER= 'investigate_further';
    const TYPE_OTHER              = 'other';

    // Status machine
    const STATUS_PENDING     = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED   = 'completed';
    const STATUS_CANCELLED   = 'cancelled';

    // Human-readable type labels
    const TYPE_LABELS = [
        self::TYPE_REORDER             => 'Reorder Stock',
        self::TYPE_TRANSFER            => 'Transfer Stock',
        self::TYPE_PRICE_ADJUSTMENT    => 'Price Adjustment',
        self::TYPE_SUPPLIER_CONTACT    => 'Contact Supplier',
        self::TYPE_WRITE_OFF           => 'Write Off',
        self::TYPE_DISCOUNT_REMOVAL    => 'Remove Discount',
        self::TYPE_MONITOR             => 'Monitor',
        self::TYPE_INVESTIGATE_FURTHER => 'Investigate Further',
        self::TYPE_OTHER               => 'Other',
    ];

    protected $fillable = [
        'investigation_id',
        'anomaly_id',
        'action_type',
        'title',
        'description',
        'notes',
        'status',
        'assigned_to',
        'assigned_team_id',
        'created_by',
        'due_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'due_at'       => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
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

    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED   => 'success',
            self::STATUS_IN_PROGRESS => 'info',
            self::STATUS_CANCELLED   => 'gray',
            default                  => 'warning',
        };
    }

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->action_type] ?? ucwords(str_replace('_', ' ', $this->action_type));
    }

    public function isOverdue(): bool
    {
        return $this->due_at && $this->due_at->isPast()
            && !in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }
}
