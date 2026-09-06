<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2.1 — the round-trip record of one outbound decision dispatch. Part of
 * Platform\Integration.
 */
class OutboundDispatch extends Model
{
    public const STATUS_PENDING      = 'pending';
    public const STATUS_SENT         = 'sent';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_FAILED       = 'failed';

    protected $fillable = [
        'tenant_id',
        'target_id',
        'intent_type',
        'source',
        'request_payload',
        'status',
        'response_code',
        'response_body',
        'dispatched_at',
        'completed_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'dispatched_at'   => 'datetime',
        'completed_at'    => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(OutboundTarget::class, 'target_id');
    }
}
