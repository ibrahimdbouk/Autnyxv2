<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** v2.3 — a chain-wide tail clean-up: remove these products wherever the engine proposes it. */
class AssortmentProgramme extends Model
{
    public const PROPOSED = 'proposed';
    public const ACCEPTED = 'accepted';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PROPOSED  => 'Proposed',
        self::ACCEPTED  => 'Under way',
        self::DONE      => 'Done',
        self::CANCELLED => 'Cancelled',
    ];

    protected $fillable = ['tenant_id', 'name', 'status', 'skus', 'gap_ids', 'value', 'metres', 'stores', 'assignee_id', 'due_at', 'created_by', 'decided_by', 'decided_at'];

    protected $casts = [
        'skus'       => 'array',
        'gap_ids'    => 'array',
        'value'      => 'array',
        'metres'     => 'float',
        'due_at'     => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
