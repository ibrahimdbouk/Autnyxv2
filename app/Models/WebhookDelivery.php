<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** W13 — one event sent (or being sent) to one webhook endpoint. */
class WebhookDelivery extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = ['tenant_id', 'webhook_endpoint_id', 'event_id', 'event', 'payload', 'status', 'attempts',
        'response_status', 'response_excerpt', 'next_attempt_at', 'delivered_at'];

    protected $casts = [
        'payload'         => 'array',
        'next_attempt_at' => 'datetime',
        'delivered_at'    => 'datetime',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
