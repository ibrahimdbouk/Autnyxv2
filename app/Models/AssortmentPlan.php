<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assortment v1.5 — a range plan for one store × category (the optimisation
 * object): current range against the proposed one, as a set of changes chosen
 * together. A proposal only; accepting it makes one reset task.
 */
class AssortmentPlan extends Model
{
    public const STATUS_DRAFT       = 'draft';
    public const STATUS_PROPOSED    = 'proposed';
    public const STATUS_ACCEPTED    = 'accepted';
    public const STATUS_REJECTED    = 'rejected';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_MEASURED    = 'measured';

    public const STATUSES = [
        self::STATUS_DRAFT       => 'In review',
        self::STATUS_PROPOSED    => 'Proposed',
        self::STATUS_ACCEPTED    => 'Accepted',
        self::STATUS_REJECTED    => 'Rejected',
        self::STATUS_IN_PROGRESS => 'Done, measuring',
        self::STATUS_MEASURED    => 'Measured',
    ];

    /** Kinds of change in a plan. */
    public const ADD     = 'add';
    public const DELIST  = 'delist';
    public const SWAP    = 'swap';
    public const RECOVER = 'recover';
    public const PROTECT = 'protect';

    public const CHANGE_LABELS = [
        self::ADD     => 'Add',
        self::DELIST  => 'Delist',
        self::SWAP    => 'Swap',
        self::RECOVER => 'Fix stock',
        self::PROTECT => 'Protected',
    ];

    protected $fillable = [
        'tenant_id', 'store_id', 'category', 'peer_group', 'status', 'role', 'objective', 'feasible', 'infeasible_reason',
        'current_count', 'proposed_count', 'changes', 'impact', 'constraints', 'value_mid', 'confidence', 'confidence_tier',
        'fingerprint', 'optimizer_version', 'as_of_date', 'review_verdict', 'reviewed_by', 'reviewed_at',
        'decided_by', 'decided_at', 'decision_note', 'assignee_id', 'due_at', 'done_at', 'done_by',
        'measure_after', 'measured_at', 'measurement',
    ];

    protected $casts = [
        'feasible'      => 'boolean',
        'changes'       => 'array',
        'impact'        => 'array',
        'constraints'   => 'array',
        'value_mid'     => 'float',
        'confidence'    => 'float',
        'as_of_date'    => 'date',
        'reviewed_at'   => 'datetime',
        'decided_at'    => 'datetime',
        'due_at'        => 'datetime',
        'done_at'       => 'datetime',
        'measure_after' => 'date',
        'measured_at'   => 'datetime',
        'measurement'   => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Changes a person acts on (not the protected list), in the optimiser's order. */
    public function actionable(): array
    {
        return array_values(array_filter($this->getAttribute('changes') ?? [], fn ($c) => ($c['kind'] ?? '') !== self::PROTECT));
    }

    public function headline(): string
    {
        $n = count(array_filter($this->actionable(), fn ($c) => ($c['kind'] ?? '') !== self::RECOVER));

        return "{$this->category} at " . ($this->store?->name ?? 'store #' . $this->store_id) . ": {$this->current_count} → {$this->proposed_count} products"
            . ($n ? " ({$n} change" . ($n === 1 ? '' : 's') . ')' : '');
    }
}
