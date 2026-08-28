<?php

namespace App\Models;

use App\Services\Recovery\AnomalyIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Anomaly extends Model
{
    use HasFactory;

    // ── Severity ──────────────────────────────────────────────────────────────
    const SEVERITY_LOW    = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH   = 'high';

    // ── Investigation state machine ───────────────────────────────────────────
    const STATUS_DETECTED          = 'detected';
    const STATUS_INVESTIGATING     = 'investigating';
    const STATUS_CAUSE_ESTABLISHED = 'cause_established';
    const STATUS_ACTION_TAKEN      = 'action_taken';
    const STATUS_RESOLVED          = 'resolved';
    const STATUS_UNRESOLVED        = 'unresolved';

    // ── Recovery lifecycle (R1) ───────────────────────────────────────────────
    // States advanced by the nightly reconciliation job (R2). Recurrence after
    // resolution is modelled as a fresh episode linked via previous_episode_id.
    const LIFECYCLE_OPEN       = 'open';
    const LIFECYCLE_PERSISTING = 'persisting';
    const LIFECYCLE_CLEARING   = 'clearing';
    const LIFECYCLE_RESOLVED   = 'resolved';

    // ── Confidence tiers ──────────────────────────────────────────────────────
    const CONFIDENCE_ESTABLISHED = 'established';
    const CONFIDENCE_PROBABLE    = 'probable';
    const CONFIDENCE_SUSPECTED   = 'suspected';
    const CONFIDENCE_UNKNOWN     = 'unknown';

    // ── Evidence gates ────────────────────────────────────────────────────────
    const GATE_ACT         = 'act';
    const GATE_INVESTIGATE = 'investigate';
    const GATE_MONITOR     = 'monitor';

    protected $fillable = [
        'tenant_id',
        'investigation_id',  // FK to investigations — set by InvestigationCorrelationService
        'rule_type',
        'severity',
        'sku',
        'store_id',
        'product_id',
        'description',
        'context',
        'detected_at',
        'dismissed_at',
        'dismissed_by',
        'is_false_positive',
        'notified_at',
        // Investigation fields
        'investigation_status',
        'ai_what',
        'ai_why',
        'ai_confidence',
        'ai_how_big',
        'ai_trajectory',
        'ai_action',
        'ai_recommendation_gate',
        'ai_outcome',
        'ai_pattern',
        'ai_is_recurring',
        'ai_related_anomaly_ids',
        'ai_related_summary',
        'ai_generated_at',
        'action_taken_at',
        'action_notes',
        'resolved_at',
        'resolution_notes',
        // Recovery lifecycle (R1)
        'identity_key',
        'episode_seq',
        'lifecycle_state',
        'first_seen_at',
        'last_seen_at',
        'cleared_at',
        'clear_streak',
        'occurrence_count',
        'previous_episode_id',
        'value_at_open',
        'backfilled',
    ];

    protected $casts = [
        'context'               => 'array',
        'ai_related_anomaly_ids'=> 'array',
        'ai_is_recurring'       => 'boolean',
        'is_false_positive'     => 'boolean',
        'detected_at'           => 'datetime',
        'dismissed_at'          => 'datetime',
        'ai_generated_at'       => 'datetime',
        'action_taken_at'       => 'datetime',
        'resolved_at'           => 'datetime',
        // Recovery lifecycle (R1)
        'first_seen_at'         => 'datetime',
        'last_seen_at'          => 'datetime',
        'cleared_at'            => 'datetime',
        'value_at_open'         => 'float',
        'episode_seq'           => 'integer',
        'clear_streak'          => 'integer',
        'occurrence_count'      => 'integer',
        'backfilled'            => 'boolean',
    ];

    // ── Boot: materialise derived identity + freeze value-at-open on create ────
    // Identity is deterministic (a pure function of tenant/rule/store/sku), so
    // computing it here is materialising a derived value, not a lifecycle
    // decision — the R2 reconciliation job stays the single writer of *status*.
    protected static function booted(): void
    {
        static::creating(function (Anomaly $anomaly): void {
            if (empty($anomaly->identity_key)) {
                $anomaly->identity_key = AnomalyIdentity::forAnomaly($anomaly);
            }
            if ($anomaly->first_seen_at === null) {
                $anomaly->first_seen_at = $anomaly->detected_at ?: now();
            }
            if ($anomaly->last_seen_at === null) {
                $anomaly->last_seen_at = $anomaly->first_seen_at;
            }
            if ($anomaly->value_at_open === null) {
                $ctx = $anomaly->context;
                $impact = is_array($ctx) ? ($ctx['revenue_impact'] ?? null) : null;
                $anomaly->value_at_open = $impact !== null ? (float) $impact : null;
            }
            if (empty($anomaly->lifecycle_state)) {
                $anomaly->lifecycle_state = self::LIFECYCLE_OPEN;
            }
            // Materialise the DB defaults in-memory so a freshly-created model is
            // correct without a round-trip. R2 may pass episode_seq explicitly.
            $anomaly->episode_seq      = $anomaly->episode_seq ?: 1;
            $anomaly->occurrence_count = $anomaly->occurrence_count ?: 1;
            $anomaly->clear_streak     = $anomaly->clear_streak ?? 0;
            $anomaly->backfilled       = $anomaly->backfilled ?? false;
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function dismissedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function investigationEntities(): HasMany
    {
        return $this->hasMany(InvestigationEntity::class);
    }

    /** The prior episode of this same subject, when this row is a recurrence (R2). */
    public function previousEpisode(): BelongsTo
    {
        return $this->belongsTo(Anomaly::class, 'previous_episode_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** This anomaly's estimated business impact (from context.revenue_impact), or null. */
    public function estimatedImpact(): ?float
    {
        $v = $this->context['revenue_impact'] ?? null;

        return $v === null ? null : (float) $v;
    }

    /**
     * Total estimated value at risk across a tenant's OPEN anomalies — the
     * "AED at risk surfaced" headline (B1). Sums context.revenue_impact, which
     * every money-bearing rule writes. Impact is an estimate (units at risk ×
     * price), not realised loss.
     */
    public static function estimatedValueAtRiskForTenant(int $tenantId): float
    {
        return (float) static::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('dismissed_at')
            ->sum(\Illuminate\Support\Facades\DB::raw("COALESCE((context->>'revenue_impact')::numeric, 0)"));
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    public function isInvestigated(): bool
    {
        return $this->ai_generated_at !== null;
    }

    public function getRuleLabel(): string
    {
        return AnomalySetting::RULES[$this->rule_type]['label'] ?? $this->rule_type;
    }

    public function getConfidenceLabel(): string
    {
        return match ($this->ai_confidence) {
            self::CONFIDENCE_ESTABLISHED => 'Established',
            self::CONFIDENCE_PROBABLE    => 'Probable',
            self::CONFIDENCE_SUSPECTED   => 'Suspected',
            default                      => 'Unknown',
        };
    }

    public function getConfidenceColor(): string
    {
        return match ($this->ai_confidence) {
            self::CONFIDENCE_ESTABLISHED => 'success',
            self::CONFIDENCE_PROBABLE    => 'info',
            self::CONFIDENCE_SUSPECTED   => 'warning',
            default                      => 'gray',
        };
    }

    public function getGateLabel(): string
    {
        return match ($this->ai_recommendation_gate) {
            self::GATE_ACT         => 'Act Now',
            self::GATE_INVESTIGATE => 'Investigate',
            default                => 'Monitor',
        };
    }

    public function getGateColor(): string
    {
        return match ($this->ai_recommendation_gate) {
            self::GATE_ACT         => 'danger',
            self::GATE_INVESTIGATE => 'warning',
            default                => 'info',
        };
    }
}
