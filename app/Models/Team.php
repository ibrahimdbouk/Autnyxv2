<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'email',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot('role')
            ->wherePivot('role', 'lead')
            ->withTimestamps();
    }

    public function teamMemberRecords(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function investigations(): HasMany
    {
        return $this->hasMany(Investigation::class, 'assigned_team_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getMemberCount(): int
    {
        return $this->members()->count();
    }
}
