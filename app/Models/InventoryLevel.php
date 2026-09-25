<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'import_id',
        'store_id',
        'product_id',
        'sku',
        'location',
        'on_hand_qty',
        'reorder_point',
        'as_of_date',
        'on_order_qty',
        'inventory_value',
        // Hardening (2026-09-23) — safety / allocation / lot.
        'safety_stock',
        'allocated_qty',
        'in_transit_qty',
        'unit_cost',
        'batch_ref',
        'expiry_date',
    ];

    protected $casts = [
        'on_hand_qty'     => 'decimal:4',
        'reorder_point'   => 'decimal:4',
        'as_of_date'      => 'date',
        'on_order_qty'    => 'decimal:4',
        'inventory_value' => 'decimal:4',
        'safety_stock'    => 'decimal:4',
        'allocated_qty'   => 'decimal:4',
        'in_transit_qty'  => 'decimal:4',
        'unit_cost'       => 'decimal:4',
        'expiry_date'     => 'date',
    ];

    /**
     * WP6.2: a row written one at a time (not by an import, which refreshes
     * its positions in bulk) keeps inventory_current in step.
     */
    protected static function booted(): void
    {
        $refresh = function (InventoryLevel $level): void {
            $keys = [['store_id' => $level->store_id, 'sku' => $level->sku]];
            if ($level->wasChanged(['store_id', 'sku']) || $level->isDirty(['store_id', 'sku'])) {
                $keys[] = ['store_id' => $level->getOriginal('store_id'), 'sku' => $level->getOriginal('sku')];
            }
            app(\App\Services\Inventory\InventoryCurrentService::class)->refreshKeys((int) $level->tenant_id, $keys);
        };
        static::saved($refresh);
        static::deleted($refresh);
    }

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
