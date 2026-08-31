<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A store peer group produced by a clustering strategy
 * (Platform\Intelligence\Clustering). Derived data — rebuilt nightly.
 */
class StoreCluster extends Model
{
    protected $fillable = [
        'tenant_id',
        'method',
        'key',
        'label',
        'params',
    ];

    protected $casts = [
        'params' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(
            Store::class,
            'store_cluster_members',
            'store_cluster_id',
            'store_id',
        );
    }
}
