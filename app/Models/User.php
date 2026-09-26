<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasTenants, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'digest_opt_in',
        'name',
        'email',
        'password',
        'tenant_id',
        'is_super_admin',
        'is_tenant_admin',
        'visible_screens',
        'teams_aad_user_id',
        'store_digest',          // W12
        'store_digest_sent_at',  // W12
        'org_role',              // platform core: operating role (not admin rights)
        'locale',
    ];

    /**
     * Platform core — the operating role ladder. It is separate from admin
     * rights (is_tenant_admin): it decides which stores a person works in and
     * who work escalates to (store manager → area → region → HQ). Null means
     * "not set" and keeps the behaviour from before the ladder existed.
     */
    public const ORG_HQ            = 'hq';
    public const ORG_AREA_MANAGER  = 'area_manager';
    public const ORG_STORE_MANAGER = 'store_manager';
    public const ORG_ASSOCIATE     = 'associate';

    public const ORG_ROLES = [
        self::ORG_HQ            => 'Head office',
        self::ORG_AREA_MANAGER  => 'Area / regional manager',
        self::ORG_STORE_MANAGER => 'Store manager',
        self::ORG_ASSOCIATE     => 'Store associate',
    ];

    /** Languages a person can work in (the mobile app and messages follow it). */
    public const LOCALES = [
        'en' => 'English',
        'ar' => 'العربية — Arabic',
        'ur' => 'اردو — Urdu',
        'hi' => 'हिन्दी — Hindi',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
            'is_owner'          => 'boolean',
            'is_tenant_admin'   => 'boolean',
            'visible_screens'   => 'array',
            'last_login_at'     => 'datetime',
            'store_digest'         => 'boolean',   // W12
            'store_digest_sent_at' => 'datetime',
            'deactivated_at'       => 'datetime',
            // MFA (3b) — encrypted at rest so a DB leak never exposes them.
            'app_authentication_secret'         => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * The protected platform owner — the single super-super-admin. Identified by
     * email (config/autnyx.php › owner_email) so it survives re-seeding.
     */
    /**
     * WP2.1 (audit M1): ownership is an immutable flag set only by the seeder /
     * console — never derived from the (changeable) email address, so nobody can
     * become owner by taking over the owner's email.
     */
    public function isOwner(): bool
    {
        return (bool) ($this->is_owner ?? false);
    }

    /**
     * 1a — audit access-control changes (screen visibility + role) so grants and
     * revocations are traceable (SOC 2 / ISO 27001). Append-only, best-effort:
     * an audit failure must never block the user save.
     *
     * Also enforces the owner protections (undemotable, only the owner mints
     * super admins, undeletable) at the MODEL level — the hard code, not just the
     * UI — so they hold on every path.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            // WP2.1: ownership can only be granted/removed outside the app (seeder /
            // console, no authenticated actor). Any in-app change is reverted.
            if ($user->isDirty('is_owner') && auth()->check()) {
                $user->is_owner = (bool) ($user->getOriginal('is_owner') ?? false);
            }

            // The owner is always a super admin — never demote them.
            if ($user->isOwner()) {
                $user->is_super_admin = true;
                return;
            }

            // Only the owner may grant super-admin. Backstop for the owner-only
            // UI toggle: if a non-owner actor tries to elevate someone, silently
            // revert. Seeding / console (no authenticated actor) is trusted.
            if ($user->isDirty('is_super_admin') && $user->is_super_admin) {
                $actor = auth()->user();
                if ($actor !== null && ! $actor->isOwner()) {
                    $user->is_super_admin = (bool) ($user->getOriginal('is_super_admin') ?? false);
                }
            }
        });

        static::deleting(function (User $user): void {
            if ($user->isOwner()) {
                throw new \RuntimeException('The owner account is protected and cannot be deleted.');
            }
        });

        static::updated(function (User $user): void {
            $watched = ['visible_screens', 'is_tenant_admin', 'is_super_admin', 'org_role', 'deactivated_at'];
            $changed = array_values(array_intersect($watched, array_keys($user->getChanges())));

            // audit_logs.tenant_id is NOT NULL — skip tenantless (platform) users.
            if ($changed === [] || $user->tenant_id === null) {
                return;
            }

            try {
                $old = [];
                $new = [];
                foreach ($changed as $field) {
                    $old[$field] = $user->getOriginal($field);
                    $new[$field] = $user->getAttribute($field);
                }

                AuditLog::create([
                    'tenant_id'   => $user->tenant_id,
                    'user_id'     => auth()->id(),
                    'event_type'  => AuditLog::EVENT_SCREEN_ACCESS_CHANGED,
                    'description' => 'Access changed for ' . ($user->email ?? ('user #' . $user->id)),
                    'old_value'   => $old,
                    'new_value'   => $new,
                ]);
            } catch (\Throwable $e) {
                // best-effort — never break the save on an audit write.
            }
        });

        // WP2.4 (audit H8/L3): credential changes are audited (values never logged).
        static::updated(function (User $user): void {
            $credential = array_values(array_intersect(['email', 'password'], array_keys($user->getChanges())));
            if ($credential === [] || $user->tenant_id === null) {
                return;
            }
            try {
                AuditLog::create([
                    'tenant_id'   => $user->tenant_id,
                    'user_id'     => auth()->id(),
                    'event_type'  => 'credentials_changed',
                    'description' => 'Changed ' . implode(' and ', $credential) . ' for user #' . $user->id
                        . (auth()->id() === $user->id ? ' (self-service)' : ' (by another user)'),
                    'old_value'   => in_array('email', $credential, true) ? ['email' => $user->getOriginal('email')] : null,
                    'new_value'   => in_array('email', $credential, true) ? ['email' => $user->email] : null,
                ]);
            } catch (\Throwable) {
                // best-effort
            }
        });
    }

    // ---------- Relationships ----------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ---------- Role Helpers ----------

    /**
     * Platform-level super admin: can access and manage all tenants.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Tenant-level admin: can manage users and imports within their tenant.
     */
    public function isTenantAdmin(): bool
    {
        return (bool) $this->is_tenant_admin;
    }

    /**
     * Can manage users (create, edit, assign roles within their scope).
     */
    /** W12: the store(s) this person runs — their store digest and store sheet cover these only. */
    public function stores(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_user')->withPivot('tenant_id')->withTimestamps();
    }

    /**
     * The stores this user's store-level screens are limited to — null means
     * every store. Admins and head office: null. Area managers: the stores in
     * the regions / areas they manage (plus any linked directly). Store
     * managers and associates: their linked stores — none linked means none
     * (fails closed). No operating role set: the linked stores, or every store
     * when none are linked (the behaviour from before the role ladder).
     *
     * @return array<int,int>|null
     */
    public function storeScope(): ?array
    {
        return app(\App\Services\Org\OrgDirectory::class)->storeScope($this);
    }

    /** Platform core: the regions / areas this person manages. */
    public function managedNodes(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(LocationNode::class, 'location_node_managers')->withPivot('tenant_id')->withTimestamps();
    }

    /** Platform core: phones registered for push. */
    public function devices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereNull($q->getModel()->getTable() . '.deactivated_at');
    }

    public function orgRoleLabel(): ?string
    {
        return self::ORG_ROLES[$this->org_role] ?? null;
    }

    public function canManageUsers(): bool
    {
        return $this->is_super_admin || $this->is_tenant_admin;
    }

    /**
     * 1a — may this user see the given gate-able screen (a key from
     * App\Support\Screens\ScreenRegistry)?
     *
     *   • admins (super / tenant) → always true (they see everything).
     *   • visible_screens is null → unrestricted (see all gate-able screens).
     *   • visible_screens is an array → only the keys it contains.
     *
     * The Dashboard and other always-on screens are never registered as
     * gate-able, so a restricted user is never left without a landing page.
     */
    public function canSeeScreen(string $screenKey): bool
    {
        if ($this->is_super_admin || $this->is_tenant_admin) {
            return true;
        }

        $allowed = $this->visible_screens;

        // Null / unset = unrestricted; an explicit list restricts to its keys.
        if ($allowed === null) {
            return true;
        }

        return in_array($screenKey, $allowed, true);
    }

    /**
     * Can manage imports (upload, review mappings, approve/reject rows).
     */
    public function canManageImports(): bool
    {
        return $this->is_super_admin || $this->is_tenant_admin;
    }

    // Anomaly management

    public function canDismissAnomalies(): bool
    {
        return $this->is_super_admin || $this->is_tenant_admin;
    }

    public function canChangeAnomalyThresholds(): bool
    {
        return $this->is_super_admin || $this->is_tenant_admin;
    }

    // Investigation management

    public function canReassignInvestigation(): bool
    {
        return $this->is_super_admin || $this->is_tenant_admin;
    }

    public function canCloseInvestigation(): bool
    {
        return $this->is_super_admin || $this->is_tenant_admin;
    }

    public function canChangeInvestigationStatus(): bool
    {
        return $this->is_super_admin || $this->is_tenant_admin;
    }

    /**
     * WP1.3 (audit H9): may this user move THIS investigation along its working
     * states (start / resolve)? Admins always; otherwise only the person or a
     * member of the team it is assigned to. Closing and recording financial
     * outcomes stay admin-only (canCloseInvestigation / canRecordOutcomes).
     */
    public function canWorkInvestigation(\App\Models\Investigation $investigation): bool
    {
        if ((int) $investigation->tenant_id !== (int) $this->tenant_id && ! $this->is_super_admin) {
            return false;
        }
        if ($this->canChangeInvestigationStatus()) {
            return true;
        }
        if ($investigation->assigned_user_id !== null && (int) $investigation->assigned_user_id === (int) $this->id) {
            return true;
        }

        return $investigation->assigned_team_id !== null
            && \Illuminate\Support\Facades\DB::table('team_members')
                ->where('team_id', $investigation->assigned_team_id)
                ->where('user_id', $this->id)
                ->exists();
    }

    // Financial outcomes

    public function canRecordOutcomes(): bool
    {
        return $this->is_super_admin || $this->is_tenant_admin;
    }

    // Data exports

    public function canExportData(): bool
    {
        return true; // All authenticated users can export
    }

    // Audit logs

    public function canViewAuditLogs(): bool
    {
        return $this->is_super_admin || $this->is_tenant_admin;
    }

    /**
     * Human-readable role label.
     */
    public function roleLabel(): string
    {
        if ($this->is_super_admin) return 'Super Admin';
        if ($this->is_tenant_admin) return 'Tenant Admin';
        return 'User';
    }

    // ---------- MFA / 2FA (Filament app-authenticator) ----------

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    /** The label shown in the user's authenticator app for this account. */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return array<int, string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param array<int, string>|null $codes */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    // ---------- Filament Contracts ----------

    /**
     * The tenant panel is open to all authenticated users (screen visibility
     * controls what they see inside it); the /ops control plane (2a) is
     * super-admin only.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Platform core: a deactivated person (a leaver) cannot sign in anywhere.
        if ($this->deactivated_at !== null) {
            return false;
        }
        if ($panel->getId() === 'ops') {
            return (bool) $this->is_super_admin;
        }

        return true;
    }

    /**
     * Super-admins see every tenant; regular users see only their own.
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->is_super_admin) {
            return Tenant::orderBy('name')->get();
        }

        return $this->tenant ? collect([$this->tenant]) : collect();
    }

    /**
     * Super-admins can access any tenant; regular users only their own.
     *
     * Suspension (2e/2b) lockout: a suspended tenant's own users are blocked
     * from its panel until it is reactivated. Super admins are exempt (they
     * manage suspension), and an active impersonation session is exempt too so
     * support can still inspect a suspended tenant.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        // WP6.7: a tenant being erased is closed to everyone, super admins included.
        if (($tenant->status ?? null) === Tenant::STATUS_ERASING) {
            return false;
        }

        if ($this->is_super_admin) {
            return true;
        }

        if ($this->tenant_id !== $tenant->id) {
            return false;
        }

        // Locked out while suspended — unless this is a super-admin impersonating.
        if (($tenant->status ?? Tenant::STATUS_ACTIVE) === Tenant::STATUS_SUSPENDED
            && ! session()->has(\App\Http\Controllers\ImpersonationController::SESSION_KEY)) {
            return false;
        }

        return true;
    }
}
