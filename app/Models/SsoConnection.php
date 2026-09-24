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
        'verified_domains' => 'array',
        'client_secret'    => 'encrypted',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected static function booted(): void
    {
        // WP2.1: a verified domain that is no longer allowed stops being verified.
        static::saving(function (SsoConnection $c): void {
            if ($c->isDirty('allowed_domains') && ! empty($c->verified_domains)) {
                $c->verified_domains = array_values(array_intersect($c->verifiedDomains(), $c->allowedDomains()));
            }
        });
    }

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

    /** Domains this connection has PROVEN it owns (DNS TXT) — a subset of the allowed ones. */
    public function verifiedDomains(): array
    {
        $allowed = $this->allowedDomains();

        return array_values(array_filter(array_map(
            fn ($d) => strtolower(trim((string) $d)),
            $this->verified_domains ?? []
        ), fn ($d) => $d !== '' && in_array($d, $allowed, true)));
    }

    /**
     * Is this email's domain permitted to sign in through this connection?
     * WP2.1 (audit M8): only VERIFIED domains — there is no "any domain" mode,
     * so a connection can never sign in people from a domain it does not own.
     */
    public function permitsEmail(?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return false;
        }

        $domain = substr($email, strpos($email, '@') + 1);

        return in_array($domain, $this->verifiedDomains(), true);
    }
}
