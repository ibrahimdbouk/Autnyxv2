<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform core — a phone (or browser) registered to receive push
 * notifications for one user. The token is stored encrypted and looked up by
 * its hash; a device is revoked (never reused) when its user is deactivated,
 * leaves the tenant or signs out. See App\Services\Org\DeviceRegistry.
 */
class UserDevice extends Model
{
    public const PLATFORMS = ['ios', 'android', 'web'];

    protected $fillable = [
        'tenant_id', 'user_id', 'platform', 'token_hash', 'token', 'device_name', 'app_version', 'locale',
        'last_seen_at', 'revoked_at', 'revoked_reason',
    ];

    protected $hidden = ['token', 'token_hash'];

    protected $casts = [
        'token'        => 'encrypted',
        'last_seen_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at');
    }
}
