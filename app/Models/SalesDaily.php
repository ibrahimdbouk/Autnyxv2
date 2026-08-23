<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incrementally-maintained daily sales aggregate (see SalesDailyAggregator).
 * One row per tenant / store / SKU / day.
 */
class SalesDaily extends Model
{
    protected $table = 'sales_daily';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'sku',
        'date',
        'units_sold',
        'revenue',
        'transaction_count',
    ];

    protected $casts = [
        'date'              => 'date',
        'units_sold'        => 'decimal:4',
        'revenue'           => 'decimal:4',
        'transaction_count' => 'integer',
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
