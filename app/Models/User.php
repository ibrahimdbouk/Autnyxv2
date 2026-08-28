<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'is_super_admin',
        'is_tenant_admin',
        'visible_screens',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
            'is_tenant_admin'   => 'boolean',
            'visible_screens'   => 'array',
        ];
    }

    /**
     * 1a — audit access-control changes (screen visibility + role) so grants and
     * revocations are traceable (SOC 2 / ISO 27001). Append-only, best-effort:
     * an audit failure must never block the user save.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            $watched = ['visible_screens', 'is_tenant_admin', 'is_super_admin'];
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

    // ---------- Filament Contracts ----------

    /**
     * The tenant panel is open to all authenticated users (screen visibility
     * controls what they see inside it); the /ops control plane (2a) is
     * super-admin only.
     */
    public function canAccessPanel(Panel $panel): bool
    {
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
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->tenant_id === $tenant->id;
    }
}
