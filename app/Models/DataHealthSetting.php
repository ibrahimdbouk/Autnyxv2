<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DataHealthSetting — Feature 4
 *
 * Tenant-configurable thresholds per dataset. Defaults live in DataHealthService.
 */
class DataHealthSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'dataset',
        'freshness_max_hours',
        'completeness_min_pct',
        'rejection_max_pct',
    ];

    protected $casts = [
        'freshness_max_hours'  => 'integer',
        'completeness_min_pct' => 'float',
        'rejection_max_pct'    => 'float',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
