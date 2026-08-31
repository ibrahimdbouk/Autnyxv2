<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned container for a tenant's clusters under one (strategy, objective)
 * (Platform\Intelligence). `version` bumps only on material change; a recommendation
 * can later reference {cluster_set_id, version, cluster_key} to stay auditable.
 */
class ClusterSet extends Model
{
    protected $fillable = [
        'tenant_id', 'strategy', 'objective', 'version', 'signature', 'computed_at',
    ];

    protected $casts = [
        'version'     => 'integer',
        'computed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function clusters(): HasMany
    {
        return $this->hasMany(StoreCluster::class);
    }
}
