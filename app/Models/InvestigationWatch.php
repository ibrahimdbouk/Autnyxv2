<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * InvestigationWatch — Feature 5
 *
 * A user or team subscribes to meaningful changes on an investigation.
 */
class InvestigationWatch extends Model
{
    const MODE_UNTIL_RESOLVED = 'until_resolved';
    const MODE_UNTIL_DATE     = 'until_date';
    const MODE_INDEFINITE     = 'indefinite';

    // Trigger keys
    const TRIGGER_STATUS_CHANGE          = 'status_change';
    const TRIGGER_ESCALATION             = 'escalation';
    const TRIGGER_ACTION_TAKEN           = 'action_taken';
    const TRIGGER_OVERDUE                = 'overdue';
    const TRIGGER_MATERIAL_IMPACT_CHANGE = 'material_impact_change';
    const TRIGGER_RECOVERY               = 'recovery';
    const TRIGGER_RESOLUTION             = 'resolution';
    const TRIGGER_COMMENT                = 'comment';

    const TRIGGER_LABELS = [
        self::TRIGGER_STATUS_CHANGE          => 'Status change',
        self::TRIGGER_ESCALATION             => 'Escalation',
        self::TRIGGER_ACTION_TAKEN           => 'Action taken',
        self::TRIGGER_OVERDUE                => 'Overdue',
        self::TRIGGER_MATERIAL_IMPACT_CHANGE => 'Material impact change',
        self::TRIGGER_RECOVERY               => 'Recovery detected',
        self::TRIGGER_RESOLUTION             => 'Resolution',
        self::TRIGGER_COMMENT                => 'New comment',
    ];

    const DEFAULT_TRIGGERS = [
        self::TRIGGER_STATUS_CHANGE,
        self::TRIGGER_ESCALATION,
        self::TRIGGER_ACTION_TAKEN,
        self::TRIGGER_MATERIAL_IMPACT_CHANGE,
        self::TRIGGER_RECOVERY,
        self::TRIGGER_RESOLUTION,
    ];

    protected $fillable = [
        'tenant_id',
        'investigation_id',
        'user_id',
        'team_id',
        'mode',
        'watch_until',
        'triggers',
        'active',
        'last_state',
        'last_evaluated_at',
        'created_by',
        'ended_by',
        'ended_at',
    ];

    protected $casts = [
        'triggers'          => 'array',
        'last_state'        => 'array',
        'active'            => 'boolean',
        'watch_until'       => 'datetime',
        'last_evaluated_at' => 'datetime',
        'ended_at'          => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(WatchNotification::class, 'watch_id');
    }

    public function wantsTrigger(string $trigger): bool
    {
        $triggers = $this->triggers ?: self::DEFAULT_TRIGGERS;
        return in_array($trigger, $triggers, true);
    }

    /**
     * Resolve the set of user IDs that should be notified for this watch.
     */
    public function recipientUserIds(): array
    {
        if ($this->user_id) {
            return [$this->user_id];
        }
        if ($this->team_id && $this->team) {
            return $this->team->members()->pluck('users.id')->all();
        }
        return [];
    }

    public function getWatcherLabel(): string
    {
        if ($this->user_id) {
            return $this->user?->name ?? 'User';
        }
        if ($this->team_id) {
            return ($this->team?->name ?? 'Team') . ' (team)';
        }
        return 'Unknown';
    }
}
