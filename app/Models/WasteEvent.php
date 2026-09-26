<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W11 — a waste / write-off line (the "waste" import type): quantity thrown
 * away or written off for a SKU at a store on a day, with a reason.
 */
class WasteEvent extends Model
{
    protected $table = 'waste_events';

    protected $fillable = [
        'tenant_id', 'import_id', 'date', 'sku', 'product_id', 'store_id', 'location',
        'quantity', 'value', 'reason', 'waste_ref',
    ];

    protected $casts = [
        'date'     => 'date',
        'quantity' => 'float',
        'value'    => 'float',
    ];
}
