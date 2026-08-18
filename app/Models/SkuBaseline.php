<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkuBaseline extends Model
{
    protected $fillable = [
        'tenant_id',
        'sku',
        'store_id',        // null = retailer-wide fallback; non-null = store-level preferred
        'rule_type',
        'metric',
        'baseline_mean',
        'baseline_stddev',
        'sample_count',
        'sensitivity_multiplier',
        'fp_count',
        'computed_at',
    ];

    protected $casts = [
        'baseline_mean'          => 'float',
        'baseline_stddev'        => 'float',
        'sensitivity_multiplier' => 'float',
        'computed_at'            => 'datetime',
    ];
}
