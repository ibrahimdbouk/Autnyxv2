<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P2.1 — a tenant's outbound target (its replenishment system of record). Part
 * of Platform\Integration.
 */
class OutboundTarget extends Model
{
    public const KIND_WEBHOOK = 'webhook';
    public const KIND_LOG     = 'log';

    protected $fillable = [
        'tenant_id',
        'kind',
        'name',
        'endpoint',
        'config',
        'active',
    ];

    protected $casts = [
        'config' => 'array',
        'active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(OutboundDispatch::class, 'target_id');
    }
}
