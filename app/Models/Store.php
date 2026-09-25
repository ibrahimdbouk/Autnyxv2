<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'address',
        'city',
        'region',
        'country',
        'format',
        // Hardening (2026-09-23) — geography + operating attributes.
        'postal_code',
        'latitude',
        'longitude',
        'phone',
        'email',
        'timezone',
        'currency',
        'banner',
        'status',
        'opened_on',
        'sales_area_sqm',
    ];

    protected $casts = [
        'latitude'       => 'decimal:7',
        'longitude'      => 'decimal:7',
        'sales_area_sqm' => 'decimal:2',
        'opened_on'      => 'date',
    ];

    /** WP6.5: the canonical hierarchy follows (once per tenant, at the end of the request). */
    protected static function booted(): void
    {
        static::saved(fn (self $m) => \App\Services\Platform\HierarchySync::later((int) $m->tenant_id));
        static::deleted(fn (self $m) => \App\Services\Platform\HierarchySync::later((int) $m->tenant_id));
    }

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

    /** WP6.2: the store's current stock positions (one per SKU). */
    public function currentPositions(): HasMany
    {
        return $this->hasMany(InventoryCurrent::class);
    }

    /** This store's behavioural feature vector (Platform\Intelligence), if computed. */
    public function feature(): HasOne
    {
        return $this->hasOne(StoreFeature::class);
    }

    /** This store's leaf node in the canonical Location hierarchy (P1.1). */
    public function locationNode(): HasOne
    {
        return $this->hasOne(LocationNode::class);
    }
}
