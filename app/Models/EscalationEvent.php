<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscalationEvent extends Model
{
    protected $fillable = [
        'investigation_id',
        'escalation_rule_id',
        'trigger_reason',
        'escalation_action',
        'triggered_at',
        'to_team_id',
        'to_user_id',
        'from_priority',
        'to_priority',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function escalationRule(): BelongsTo
    {
        return $this->belongsTo(EscalationRule::class);
    }

    public function toTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'to_team_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
