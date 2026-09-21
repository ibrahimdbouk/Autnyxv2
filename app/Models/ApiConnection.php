<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ApiConnection — a tenant's connection to a source system's REST/OData API.
 * The API sibling of SftpConnection: each feed maps an endpoint to an import
 * type, and a poller pulls data through the standard import pipeline.
 * Auth secrets are encrypted at rest. See claude/api-integration-library.md.
 */
class ApiConnection extends Model
{
    const STATUS_NEVER = 'never';
    const STATUS_OK    = 'ok';
    const STATUS_ERROR = 'error';

    const AUTH_NONE       = 'none';
    const AUTH_BEARER     = 'bearer';
    const AUTH_BASIC      = 'basic';
    const AUTH_API_KEY    = 'api_key_header';
    const AUTH_OAUTH2_CC  = 'oauth2_client_credentials';

    protected $fillable = [
        'tenant_id',
        'name',
        'provider',
        'base_url',
        'auth_type',
        'auth_config',
        'is_active',
        'status',
        'last_polled_at',
        'last_error',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'last_polled_at' => 'datetime',
        // JSON credentials bag, encrypted at rest.
        'auth_config'    => 'encrypted:array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function feeds(): HasMany
    {
        return $this->hasMany(ApiFeed::class);
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_OK    => 'success',
            self::STATUS_ERROR => 'danger',
            default            => 'gray',
        };
    }

    /** A single value from the encrypted auth bag. */
    public function authValue(string $key, $default = null)
    {
        $cfg = is_array($this->auth_config) ? $this->auth_config : [];

        return $cfg[$key] ?? $default;
    }
}
