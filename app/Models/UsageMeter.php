<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.4 — a per-tenant, per-app usage counter for one metric in one period.
 * Part of Platform\Governance.
 */
class UsageMeter extends Model
{
    protected $table = 'usage_meters';

    protected $fillable = [
        'tenant_id',
        'app',
        'metric',
        'period',
        'count',
    ];

    protected $casts = [
        'count' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
