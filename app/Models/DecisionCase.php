<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.3 — one remembered decision arc. Part of Platform\Memory. $table explicit
 * (INC-012 guard).
 */
class DecisionCase extends Model
{
    protected $table = 'decision_cases';

    public const DECISION_ADOPTED  = 'adopted';
    public const DECISION_REJECTED  = 'rejected';
    public const DECISION_DEFERRED  = 'deferred';
    public const DECISION_PENDING   = 'pending';

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_PARTIAL = 'partial';
    public const OUTCOME_FAILURE = 'failure';
    public const OUTCOME_PENDING = 'pending';

    /** Outcome statuses that count as resolved (an actual result was measured). */
    public const RESOLVED = [self::OUTCOME_SUCCESS, self::OUTCOME_PARTIAL, self::OUTCOME_FAILURE];

    protected $fillable = [
        'tenant_id',
        'intent_type',
        'objective',
        'sku',
        'store_id',
        'expected_value',
        'confidence',
        'risk',
        'situation',
        'evidence',
        'recommendation',
        'outcome',
        'decision',
        'action_ref',
        'outcome_status',
        'realized_value',
        'realization_rate',
        'occurred_at',
        'resolved_at',
    ];

    protected $casts = [
        'expected_value'   => 'float',
        'confidence'       => 'float',
        'risk'             => 'float',
        'situation'        => 'array',
        'evidence'         => 'array',
        'recommendation'   => 'array',
        'outcome'          => 'array',
        'realized_value'   => 'float',
        'realization_rate' => 'float',
        'occurred_at'      => 'datetime',
        'resolved_at'      => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isResolved(): bool
    {
        return in_array($this->outcome_status, self::RESOLVED, true);
    }
}
