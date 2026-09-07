<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.8 — one staged action-intent awaiting a human decision. Part of
 * Platform\Orchestration. $table explicit (INC-012 guard).
 */
class ApprovalQueueItem extends Model
{
    protected $table = 'approval_queue';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id',
        'intent_type',
        'source',
        'request_payload',
        'reason',
        'status',
        'decided_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'decided_at'      => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
