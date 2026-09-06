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

    // ── App entitlements — Autnyx is a platform; a tenant holds a subset of apps.
    const APP_ROOT_CAUSE     = 'root_cause';
    const APP_ASSORTMENT     = 'assortment';
    const APP_TASK_EXECUTION = 'task_execution';

    const APP_LABELS = [
        self::APP_ROOT_CAUSE     => 'Root-Cause Intelligence',
        self::APP_ASSORTMENT     => 'Assortment Intelligence',
        self::APP_TASK_EXECUTION => 'Task Execution',
    ];

    /** What a tenant gets by default / falls back to: the one built app. */
    const DEFAULT_APPS = [self::APP_ROOT_CAUSE];

    protected $fillable = [
        'name',
        'slug',
        'plan',
        'status',
        'apps',
        'currency',
        'settings',
        'notification_email',
        'notify_on_high',
        'notify_on_medium',
    ];

    protected $casts = [
        'apps'              => 'array',
        'settings'          => 'array',
        'notify_on_high'    => 'boolean',
        'notify_on_medium'  => 'boolean',
        'last_detection_at' => 'datetime',
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

    // ---------- App entitlements ----------

    /** The apps this tenant may use, always valid and non-empty (falls back to Root-Cause). */
    public function enabledApps(): array
    {
        $apps = $this->apps;
        if (! is_array($apps) || $apps === []) {
            return self::DEFAULT_APPS;
        }

        // Ignore anything not a known app so a stale value can never grant access.
        $valid = array_values(array_intersect($apps, array_keys(self::APP_LABELS)));

        return $valid === [] ? self::DEFAULT_APPS : $valid;
    }

    public function hasApp(string $app): bool
    {
        return in_array($app, $this->enabledApps(), true);
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

    /** P2.2 — the business objective this tenant is optimising toward. */
    public function activeObjective(): string
    {
        return $this->settings['objective'] ?? 'general';
    }
}
