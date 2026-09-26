<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform core — a place inside a store (aisle, endcap, chiller…), optionally
 * in a department. Work is pointed at a zone: "Store 104 → Beverages →
 * Endcap #3".
 */
class StoreZone extends Model
{
    public const TYPES = [
        'aisle'         => 'Aisle',
        'endcap'        => 'Endcap',
        'gondola'       => 'Gondola',
        'promo_display' => 'Promotion display',
        'chiller'       => 'Chiller',
        'freezer'       => 'Freezer',
        'counter'       => 'Service counter',
        'backroom'      => 'Backroom / store room',
        'checkout'      => 'Checkout',
        'entrance'      => 'Entrance',
        'other'         => 'Other',
    ];

    protected $fillable = ['tenant_id', 'store_id', 'department_id', 'code', 'name', 'zone_type', 'sort', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'sort'   => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function label(): string
    {
        return ($this->department ? $this->department->name . ' › ' : '') . $this->name;
    }
}
