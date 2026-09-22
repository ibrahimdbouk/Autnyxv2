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
