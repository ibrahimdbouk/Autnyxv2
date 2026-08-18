<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EscalationRule extends Model
{
    // Trigger types
    const TRIGGER_TIME_OPEN          = 'time_open';          // open > N hours
    const TRIGGER_UNASSIGNED         = 'unassigned';         // open > N hours with no team
    const TRIGGER_NO_ACTION          = 'no_action';          // in_progress > N hours with no completed action
    const TRIGGER_PRIORITY_THRESHOLD = 'priority_threshold'; // priority reaches level

    // Escalation actions
    const ACTION_REASSIGN_TEAM    = 'reassign_team';
    const ACTION_REASSIGN_USER    = 'reassign_user';
    const ACTION_ELEVATE_PRIORITY = 'elevate_priority';
    const ACTION_NOTIFY_LEAD      = 'notify_lead';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'trigger_type',
        'trigger_value',
        'min_priority',
        'escalation_action',
        'target_team_id',
        'target_user_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function targetTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'target_team_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EscalationEvent::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Whether this rule fires for a given investigation priority.
     * Enforces min_priority filter: low ≤ medium ≤ high ≤ critical
     */
    public function appliesToPriority(string $priority): bool
    {
        $order = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        return ($order[$priority] ?? 0) >= ($order[$this->min_priority] ?? 0);
    }
}
