<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1.2 — one immutable fact in the platform event backbone. Append-only: created
 * once and never mutated. Carries valid time (occurred_at) and system time
 * (recorded_at). Part of Platform\Data.
 */
class PlatformEvent extends Model
{
    // Append-only — no updated_at. recorded_at is the create stamp.
    public $timestamps = false;

    public const TYPE_SALE       = 'sale';
    public const TYPE_RECEIPT    = 'receipt';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_ACTION     = 'action';
    public const TYPE_OUTCOME    = 'outcome';
    public const TYPE_ANOMALY    = 'anomaly';

    protected $fillable = [
        'tenant_id',
        'event_type',
        'occurred_at',
        'recorded_at',
        'sku',
        'store_id',
        'product_id',
        'quantity',
        'value',
        'source',
        'source_ref',
        'payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
        'quantity'    => 'decimal:4',
        'value'       => 'decimal:4',
        'payload'     => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }

    public function scopeForSku(Builder $query, string $sku): Builder
    {
        return $query->where('sku', $sku);
    }

    /** Events whose valid time falls in [$from, $to]. */
    public function scopeOccurredBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }
}
