<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W10 — one promotion on one SKU (at one store, or every store when store_id
 * is null), from the promotions import type. Read by PromotionCalendar.
 */
class Promotion extends Model
{
    protected $table = 'promotions';

    protected $fillable = [
        'tenant_id', 'import_id', 'promotion_ref', 'name', 'sku', 'product_id', 'store_id', 'location',
        'starts_on', 'ends_on', 'mechanic', 'discount_pct', 'promo_price',
    ];

    protected $casts = [
        'starts_on'    => 'date',
        'ends_on'      => 'date',
        'discount_pct' => 'float',
        'promo_price'  => 'float',
    ];
}
