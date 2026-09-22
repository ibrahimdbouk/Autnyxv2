<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $fillable = [
        'tenant_id',
        'sku',
        'name',
        'category',
        'subcategory',
        'unit_cost',
        'selling_price',
        'supplier',
        'barcode',
        'brand',
        'pack_size',
        // Hardening (2026-09-23) — merchandising attributes.
        'department',
        'uom',
        'weight_grams',
        'tax_rate',
        'gtin',
        'season',
        'status',
    ];

    protected $casts = [
        'unit_cost'     => 'decimal:4',
        'selling_price' => 'decimal:4',
        'weight_grams'  => 'decimal:2',
        'tax_rate'      => 'decimal:3',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function salesTransactions(): HasMany
    {
        return $this->hasMany(SalesTransaction::class);
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** This product's leaf node in the canonical Product hierarchy (P1.1). */
    public function productNode(): HasOne
    {
        return $this->hasOne(ProductNode::class);
    }
}
