<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1b — a tenant's OIDC single sign-on configuration.
 *
 * `client_secret` is encrypted at rest (APP_KEY). Endpoint columns are optional
 * overrides; when blank they are resolved from the issuer's discovery document
 * by OidcService.
 */
class SsoConnection extends Model
{
    protected $fillable = [
        'tenant_id',
        'enabled',
        'label',
        'issuer',
        'discovery_url',
        'authorization_endpoint',
        'token_endpoint',
        'userinfo_endpoint',
        'jwks_uri',
        'client_id',
        'client_secret',
        'scopes',
        'email_claim',
        'name_claim',
        'jit_provisioning',
        'allowed_domains',
        'admin_group_claim',
        'admin_group_value',
    ];

    protected $casts = [
        'enabled'          => 'boolean',
        'jit_provisioning' => 'boolean',
        'allowed_domains'  => 'array',
        'client_secret'    => 'encrypted',
    ];

    protected $hidden = [
        'client_secret',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The OIDC discovery document URL (explicit override, else issuer default). */
    public function discoveryUrl(): string
    {
        if (! empty($this->discovery_url)) {
            return $this->discovery_url;
        }

        return rtrim($this->issuer, '/') . '/.well-known/openid-configuration';
    }

    /** Requested scopes as an array, always including openid. */
    public function scopeList(): array
    {
        $scopes = preg_split('/\s+/', trim((string) ($this->scopes ?: 'openid email profile'))) ?: [];
        $scopes = array_values(array_filter($scopes));

        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return $scopes;
    }

    /** Normalised list of allowed email domains (lowercased), or [] for "any". */
    public function allowedDomains(): array
    {
        return array_values(array_filter(array_map(
            fn ($d) => strtolower(trim((string) $d)),
            $this->allowed_domains ?? []
        )));
    }

    /** Is this email's domain permitted to sign in through this connection? */
    public function permitsEmail(?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return false;
        }

        $domains = $this->allowedDomains();
        if ($domains === []) {
            return true; // no restriction configured
        }

        $domain = substr($email, strpos($email, '@') + 1);

        return in_array($domain, $domains, true);
    }
}
