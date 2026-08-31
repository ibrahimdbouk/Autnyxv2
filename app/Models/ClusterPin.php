<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual clustering decision, stored separately from the computed grouping and
 * re-applied after every rebuild (Platform\Intelligence). See cluster_pins migration.
 */
class ClusterPin extends Model
{
    const TYPE_MEMBERSHIP = 'membership';
    const TYPE_RENAME = 'rename';

    protected $fillable = [
        'tenant_id', 'objective', 'store_id', 'pin_type', 'target_key', 'label',
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
