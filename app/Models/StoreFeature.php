<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A store's behavioural feature vector (Platform\Intelligence). Derived data —
 * recomputed nightly by StoreProfiler. The named columns keep it explainable:
 * a store's profile can be read off directly, and clusters built on it can say
 * why a store belongs where it does.
 */
class StoreFeature extends Model
{
    protected $fillable = [
        'tenant_id', 'store_id', 'window_days',
        'revenue', 'units', 'active_skus', 'basket_count', 'avg_daily_revenue', 'growth_ratio',
        'avg_basket_value', 'avg_basket_units',
        'avg_selling_price', 'sku_productivity', 'promo_share', 'top_category', 'top_category_share',
        'dominant_segment',
        'size_tier', 'price_tier', 'basket_tier', 'descriptor',
        'features', 'computed_at',
    ];

    protected $casts = [
        'revenue'            => 'float',
        'units'              => 'float',
        'active_skus'        => 'integer',
        'basket_count'       => 'integer',
        'avg_daily_revenue'  => 'float',
        'growth_ratio'       => 'float',
        'avg_basket_value'   => 'float',
        'avg_basket_units'   => 'float',
        'avg_selling_price'  => 'float',
        'sku_productivity'   => 'float',
        'promo_share'        => 'float',
        'top_category_share' => 'float',
        'features'           => 'array',
        'computed_at'        => 'datetime',
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
