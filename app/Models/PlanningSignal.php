<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2.4 — one published signal in the outbound feed to the tenant's planning
 * system (exception / root-cause / recovery). Part of Platform\Planning.
 */
class PlanningSignal extends Model
{
    public const TYPE_EXCEPTION         = 'exception';
    public const TYPE_ROOT_CAUSE        = 'root_cause';
    public const TYPE_RECOVERY          = 'recovery';
    public const TYPE_FORECAST_OVERRIDE = 'forecast_override';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'tenant_id',
        'signal_type',
        'sku',
        'store_id',
        'severity',
        'delta',
        'rationale',
        'objective',
        'source',
        'detected_at',
        'consumed_at',
    ];

    protected $casts = [
        'delta'       => 'float',
        'detected_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
