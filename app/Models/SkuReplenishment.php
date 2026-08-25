<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B4 — derived replenishment parameters for a (store, SKU). See the
 * ReplenishmentService for how each figure is computed.
 */
class SkuReplenishment extends Model
{
    protected $table = 'sku_replenishment';

    protected $fillable = [
        'tenant_id', 'sku', 'store_id', 'supplier', 'segment',
        'daily_rate', 'lead_time_days', 'safety_stock', 'reorder_point', 'order_up_to',
        'on_hand', 'suggested_order_qty', 'unit_cost', 'order_value', 'service_level',
        'computed_at',
    ];

    protected $casts = [
        'daily_rate'          => 'float',
        'lead_time_days'      => 'float',
        'safety_stock'        => 'float',
        'reorder_point'       => 'float',
        'order_up_to'         => 'float',
        'on_hand'             => 'float',
        'suggested_order_qty' => 'float',
        'unit_cost'           => 'float',
        'order_value'         => 'float',
        'service_level'       => 'float',
        'computed_at'         => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
