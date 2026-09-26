<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W11 — one position on a store's cycle-count list, and what the count found.
 * See App\Services\Counts\CycleCountService.
 */
class CycleCount extends Model
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_COUNTED   = 'counted';
    public const STATUS_CANCELLED = 'cancelled';

    /** Rules whose finding means "the stock figure itself is in doubt". */
    public const REASON_RULES = [
        'phantom_inventory'   => 'Stock on the books but not selling',
        'inventory_shrinkage' => 'Stock falling faster than sales explain',
        'cumulative_shrink'   => 'Unexplained stock loss over weeks',
        'negative_inventory'  => 'Negative stock on hand',
    ];

    protected $fillable = [
        'tenant_id', 'store_id', 'sku', 'product_id', 'anomaly_id', 'investigation_id', 'reason',
        'system_qty', 'unit_cost', 'value_at_risk', 'rank', 'status', 'counted_qty', 'variance_qty',
        'variance_value', 'count_source', 'counted_by', 'counted_at', 'notes',
    ];

    protected $casts = [
        'system_qty'     => 'float',
        'unit_cost'      => 'float',
        'value_at_risk'  => 'float',
        'counted_qty'    => 'float',
        'variance_qty'   => 'float',
        'variance_value' => 'float',
        'counted_at'     => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function reasonLabel(): string
    {
        return self::REASON_RULES[$this->reason] ?? ucwords(str_replace('_', ' ', $this->reason));
    }
}
