<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WP6.2 — the current stock position per (store, SKU): the newest snapshot
 * with its lots summed. Written only by InventoryCurrentService.
 */
class InventoryCurrent extends Model
{
    protected $table = 'inventory_current';

    protected $guarded = ['id'];

    protected $casts = [
        'as_of_date'      => 'date',
        'earliest_expiry' => 'date',
        'on_hand_qty'     => 'decimal:4',
        'on_order_qty'    => 'decimal:4',
        'allocated_qty'   => 'decimal:4',
        'in_transit_qty'  => 'decimal:4',
        'inventory_value' => 'decimal:4',
        'reorder_point'   => 'decimal:4',
        'safety_stock'    => 'decimal:4',
        'unit_cost'       => 'decimal:4',
        'lots'            => 'integer',
    ];

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
}
