<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AgentRun — one invocation of an AI agent.
 *
 * The agent layer never decides or executes on its own: it reads deterministic
 * engine output, produces a recommendation/draft/narrative, and stores it here
 * with a confidence label and the exact inputs it saw. Agents that act
 * (Campaign Action-Plan) do so only after a human sets `acted_by` — and only
 * inside Autnyx (Actions, status changes, exports), never a customer ERP.
 */
class AgentRun extends Model
{
    // ── Agent keys ────────────────────────────────────────────────────────────
    const KEY_CAMPAIGN_PLAN   = 'campaign_action_plan';
    const KEY_WEEKLY_BRIEFING = 'weekly_briefing';
    const KEY_DATA_QUALITY    = 'data_quality';
    const KEY_ACTION_FOLLOWUP = 'action_followup';
    const KEY_SUPPLIER_PREP   = 'supplier_negotiation_prep';

    // ── Lifecycle ───────────────────────────────────────────────────────────────
    // Actioning agents: proposed → accepted → executed (or dismissed).
    // Informational agents: complete (no accept/execute step).
    // Either kind can land on failed if generation errored.
    const STATUS_PROPOSED  = 'proposed';
    const STATUS_ACCEPTED  = 'accepted';
    const STATUS_EXECUTED  = 'executed';
    const STATUS_DISMISSED = 'dismissed';
    const STATUS_COMPLETE  = 'complete';
    const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'tenant_id',
        'agent_key',
        'subject_type',
        'subject_id',
        'title',
        'status',
        'input',
        'output',
        'model',
        'confidence',
        'tokens_input',
        'tokens_output',
        'error',
        'requested_by',
        'acted_by',
        'acted_at',
        'executed_at',
    ];

    protected $casts = [
        'input'         => 'array',
        'output'        => 'array',
        'tokens_input'  => 'integer',
        'tokens_output' => 'integer',
        'acted_at'      => 'datetime',
        'executed_at'   => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isExecuted(): bool
    {
        return $this->status === self::STATUS_EXECUTED;
    }

    public function isActionable(): bool
    {
        return in_array($this->status, [self::STATUS_PROPOSED, self::STATUS_ACCEPTED], true);
    }

    /** Convenience accessor for a nested output key without exploding on nulls. */
    public function out(string $key, mixed $default = null): mixed
    {
        return data_get($this->output, $key, $default);
    }
}
