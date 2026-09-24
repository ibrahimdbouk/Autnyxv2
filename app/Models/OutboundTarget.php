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
    public const KIND_WEBHOOK     = 'webhook';
    public const KIND_LOG         = 'log';
    public const KIND_RELEX       = 'relex';
    public const KIND_BLUE_YONDER = 'blue_yonder';
    public const KIND_SLIMSTOCK   = 'slimstock';

    protected $fillable = [
        'tenant_id',
        'kind',
        'name',
        'endpoint',
        'config',
        'active',
    ];

    /** WP2.2: never serialise credentials (API output, Livewire payloads, exports). */
    protected $hidden = ['config'];

    protected $casts = [
        // WP2.2 (audit M6): tokens, client secrets and HMAC secrets live here —
        // encrypted at rest; legacy plaintext rows still read (see the cast).
        'config' => \App\Casts\EncryptedArrayWithLegacy::class,
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
