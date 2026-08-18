<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventorySnapshot extends Model
{
    protected $fillable = [
        'tenant_id',
        'sku',
        'store_id',
        'product_id',
        'on_hand_qty',
        'reorder_point',
        'unit_cost',
        'snapshot_date',
        'source',
    ];

    protected $casts = [
        'on_hand_qty'   => 'float',
        'reorder_point' => 'float',
        'unit_cost'     => 'float',
        'snapshot_date' => 'date',
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
}
