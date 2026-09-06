<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P3.4 — an ingestion data contract for a tenant feed. Part of Platform\Governance.
 * $table is explicit (INC-012 guard).
 */
class DataContract extends Model
{
    protected $table = 'data_contracts';

    protected $fillable = [
        'tenant_id',
        'feed_key',
        'required_columns',
        'freshness_sla_hours',
        'min_rows',
        'active',
    ];

    protected $casts = [
        'required_columns'    => 'array',
        'freshness_sla_hours' => 'integer',
        'min_rows'            => 'integer',
        'active'              => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function violations(): HasMany
    {
        return $this->hasMany(ContractViolation::class);
    }
}
