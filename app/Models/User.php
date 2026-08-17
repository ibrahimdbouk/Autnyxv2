<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'is_super_admin',
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
        ];
    }

    // ---------- Relationships ----------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ---------- Filament Contracts ----------

    /**
     * All authenticated users may access the panel.
     * Tenant scoping controls what they see inside it.
     */
    public function canAccessPanel(Panel $panel): bool
    {
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
