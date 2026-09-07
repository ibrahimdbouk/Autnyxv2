<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.6 — one objective's weight in a tenant's optimisation blend. Part of
 * Platform\Objectives. $table explicit (INC-012 guard).
 */
class ObjectiveWeight extends Model
{
    protected $table = 'objective_weights';

    protected $fillable = [
        'tenant_id',
        'objective',
        'weight',
        'active',
    ];

    protected $casts = [
        'weight' => 'float',
        'active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
