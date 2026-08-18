<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investigation extends Model
{
    // ── Status machine ────────────────────────────────────────────────────────
    const STATUS_OPEN        = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_RESOLVED    = 'resolved';
    const STATUS_CLOSED      = 'closed';

    // ── Priority ──────────────────────────────────────────────────────────────
    const PRIORITY_LOW      = 'low';
    const PRIORITY_MEDIUM   = 'medium';
    const PRIORITY_HIGH     = 'high';
    const PRIORITY_CRITICAL = 'critical';

    // ── AI confidence (mirrors Anomaly) ───────────────────────────────────────
    const CONFIDENCE_ESTABLISHED = 'established';
    const CONFIDENCE_PROBABLE    = 'probable';
    const CONFIDENCE_SUSPECTED   = 'suspected';
    const CONFIDENCE_UNKNOWN     = 'unknown';

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'status',
        'priority',
        'assigned_team_id',
        'assigned_user_id',
        'primary_sku',
        'primary_store_id',
        'ai_summary',
        'ai_root_cause',
        'ai_confidence',
        'ai_recommended_action',
        'ai_generated_at',
        'root_cause_notes',
        'resolution_notes',
        'revenue_at_risk',
        'observed_recovery',
        'opened_at',
        'assigned_at',
        'resolved_at',
        'closed_at',
        'anomaly_count',
    ];

    protected $casts = [
        'revenue_at_risk'   => 'float',
        'observed_recovery' => 'float',
        'anomaly_count'     => 'integer',
        'ai_generated_at'   => 'datetime',
        'opened_at'         => 'datetime',
        'assigned_at'       => 'datetime',
        'resolved_at'       => 'datetime',
        'closed_at'         => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function primaryStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'primary_store_id');
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(Anomaly::class);
    }

    public function entities(): HasMany
    {
        return $this->hasMany(InvestigationEntity::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS]);
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN        => 'warning',
            self::STATUS_IN_PROGRESS => 'info',
            self::STATUS_RESOLVED    => 'success',
            self::STATUS_CLOSED      => 'gray',
            default                  => 'gray',
        };
    }

    public function getPriorityColor(): string
    {
        return match ($this->priority) {
            self::PRIORITY_CRITICAL => 'danger',
            self::PRIORITY_HIGH     => 'warning',
            self::PRIORITY_MEDIUM   => 'info',
            default                 => 'gray',
        };
    }

    /**
     * Derive priority from the highest anomaly severity in this investigation.
     */
    public static function priorityFromSeverity(string $severity): string
    {
        return match ($severity) {
            'high'   => self::PRIORITY_HIGH,
            'medium' => self::PRIORITY_MEDIUM,
            default  => self::PRIORITY_LOW,
        };
    }

    /**
     * Refresh the denormalised anomaly_count from the DB.
     */
    public function syncAnomalyCount(): void
    {
        $this->update(['anomaly_count' => $this->anomalies()->count()]);
    }
}
