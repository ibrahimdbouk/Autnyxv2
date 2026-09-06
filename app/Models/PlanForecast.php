<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2.4 — one point of the tenant's ingested planning baseline (forecast +
 * planned order) for a (sku, store, target_date). Part of Platform\Planning.
 */
class PlanForecast extends Model
{
    protected $fillable = [
        'tenant_id',
        'sku',
        'store_id',
        'target_date',
        'forecast_qty',
        'planned_order_qty',
        'source',
        'source_ref',
        'horizon_days',
        'generated_at',
    ];

    protected $casts = [
        'target_date'       => 'date',
        'forecast_qty'      => 'float',
        'planned_order_qty' => 'float',
        'horizon_days'      => 'integer',
        'generated_at'      => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
