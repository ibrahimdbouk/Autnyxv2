<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    // ── Subscription (2b — lightweight; full billing deferred) ────────────────
    const PLAN_TRIAL      = 'trial';
    const PLAN_STANDARD   = 'standard';
    const PLAN_ENTERPRISE = 'enterprise';

    const STATUS_ACTIVE    = 'active';
    const STATUS_SUSPENDED = 'suspended';

    const PLAN_LABELS = [
        self::PLAN_TRIAL      => 'Trial',
        self::PLAN_STANDARD   => 'Standard',
        self::PLAN_ENTERPRISE => 'Enterprise',
    ];

    protected $fillable = [
        'name',
        'slug',
        'plan',
        'status',
        'currency',
        'settings',
        'notification_email',
        'notify_on_high',
        'notify_on_medium',
    ];

    protected $casts = [
        'settings'         => 'array',
        'notify_on_high'   => 'boolean',
        'notify_on_medium' => 'boolean',
    ];

    // ---------- Relationships ----------

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** 1b — this tenant's OIDC single sign-on configuration, if any. */
    public function ssoConnection(): HasOne
    {
        return $this->hasOne(SsoConnection::class);
    }

    /** 2c — this tenant's admin users (impersonation targets). */
    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('is_tenant_admin', true);
    }

    public function isActive(): bool
    {
        return ($this->status ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE;
    }

    public function planLabel(): string
    {
        return self::PLAN_LABELS[$this->plan] ?? ucfirst((string) ($this->plan ?: 'trial'));
    }

    // ---------- Currency (display-only) ----------

    /** Normalised ISO currency code for this tenant, always valid. */
    public function currencyCode(): string
    {
        return Money::normalize($this->currency);
    }

    /** Format an amount in this tenant's currency, e.g. "AED 1,234.56". */
    public function money(float|int|null $amount, int $decimals = 2): string
    {
        return Money::format($amount, $this->currencyCode(), $decimals);
    }
}
