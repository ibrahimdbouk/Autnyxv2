<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assortment: one typed range decision for a store × SKU. Status 'shadow'
 * means computed and stored but not shown to users (validation gate).
 */
class AssortmentGap extends Model
{
    public const TYPE_ADD             = 'add';
    public const TYPE_DELIST          = 'delist';
    public const TYPE_STOCKOUT_HIDDEN = 'stockout_hidden';

    public const TYPES = [
        self::TYPE_ADD             => 'Add',
        self::TYPE_DELIST          => 'Delist',
        self::TYPE_STOCKOUT_HIDDEN => 'Stockout-hidden',
    ];

    public const STATUS_SHADOW   = 'shadow';
    public const STATUS_OPEN     = 'open';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const TIER_ESTABLISHED = 'established';
    public const TIER_LIKELY      = 'likely';
    public const TIER_SPECULATIVE = 'speculative';

    public const VERDICT_SENSIBLE     = 'sensible';
    public const VERDICT_NOT_SENSIBLE = 'not_sensible';

    public const TASK_TO_DO      = 'to_do';
    public const TASK_DONE       = 'done';
    public const TASK_CANCELLED  = 'cancelled';

    protected $fillable = [
        'tenant_id', 'store_id', 'sku', 'product_id', 'type', 'status', 'peer_group',
        'value_low', 'value_mid', 'value_high', 'confidence', 'confidence_tier',
        'evidence', 'explanation', 'as_of_date', 'first_detected_at', 'last_detected_at',
        'review_verdict', 'review_note', 'reviewed_by', 'reviewed_at',
        'decided_by', 'decided_at', 'decision_note', 'value_mid_at_decision',
        'assignee_id', 'due_at', 'task_status', 'done_at', 'done_by',
        'measure_after', 'measured_at', 'measurement',
    ];

    protected $casts = [
        'value_low'         => 'float',
        'value_mid'         => 'float',
        'value_high'        => 'float',
        'confidence'        => 'float',
        'evidence'          => 'array',
        'explanation'       => 'array',
        'as_of_date'        => 'date',
        'first_detected_at' => 'datetime',
        'last_detected_at'  => 'datetime',
        'reviewed_at'       => 'datetime',
        'decided_at'        => 'datetime',
        'value_mid_at_decision' => 'float',
        'due_at'            => 'datetime',
        'done_at'           => 'datetime',
        'measure_after'     => 'date',
        'measured_at'       => 'datetime',
        'measurement'       => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** "AED 1.2K–AED 2.8K" (or "about AED 2.4K" when the range is tight). */
    public function valueRange(?string $currency): string
    {
        $lo = \App\Support\Money::compact(max(0, $this->value_low), $currency);
        $hi = \App\Support\Money::compact(max(0, $this->value_high), $currency);

        return $lo === $hi ? "about {$lo}" : "{$lo}–{$hi}";
    }

    public function headline(): string
    {
        return (string) ($this->explanation['headline'] ?? (self::TYPES[$this->type] ?? $this->type) . ' ' . $this->sku);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
