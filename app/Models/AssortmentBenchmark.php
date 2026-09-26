<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Assortment: how peer stores that carry a SKU (and keep it in stock) sell it. */
class AssortmentBenchmark extends Model
{
    public const BASIS_CLUSTER = 'cluster';
    public const BASIS_FORMAT  = 'format';

    protected $fillable = [
        'tenant_id', 'peer_group', 'peer_basis', 'sku', 'product_id', 'group_size', 'carrying', 'qualifying',
        'carried_share', 'share_index_p25', 'share_index_median', 'share_index_p75',
        'units_per_day_median', 'revenue_per_day_median', 'availability_median', 'as_of_date', 'window_days',
    ];

    protected $casts = [
        'carried_share'          => 'float',
        'share_index_p25'        => 'float',
        'share_index_median'     => 'float',
        'share_index_p75'        => 'float',
        'units_per_day_median'   => 'float',
        'revenue_per_day_median' => 'float',
        'availability_median'    => 'float',
        'as_of_date'             => 'date',
    ];
}
