<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WatchNotification — Feature 5 dedup ledger.
 */
class WatchNotification extends Model
{
    protected $fillable = [
        'watch_id',
        'tenant_id',
        'investigation_id',
        'event_type',
        'event_signature',
        'message',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function watch(): BelongsTo
    {
        return $this->belongsTo(InvestigationWatch::class, 'watch_id');
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }
}
