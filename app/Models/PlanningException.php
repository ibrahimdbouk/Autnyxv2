<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingested F&R exception (RELEX / Blue Yonder / Slimstock alert). See the
 * planning_exceptions migration and PlanningExceptionIngestor for the hybrid
 * corroboration / first-class model.
 */
class PlanningException extends Model
{
    public const DISPOSITION_CORROBORATION = 'corroboration';
    public const DISPOSITION_FIRST_CLASS   = 'first_class';

    public const STATUS_OPEN     = 'open';      // ingested, no anomaly link (parked review signal)
    public const STATUS_MATCHED  = 'matched';   // attached to / raised an anomaly
    public const STATUS_RESOLVED = 'resolved';  // cleared upstream (dropped from the snapshot)

    protected $fillable = [
        'tenant_id',
        'source',
        'external_ref',
        'sku',
        'store_id',
        'external_type',
        'category',
        'disposition',
        'severity',
        'message',
        'occurred_at',
        'payload',
        'status',
        'matched_anomaly_id',
        'anomaly_id',
    ];

    protected $casts = [
        'occurred_at' => 'date',
        'payload'     => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function anomaly(): BelongsTo
    {
        return $this->belongsTo(Anomaly::class, 'anomaly_id');
    }

    public function matchedAnomaly(): BelongsTo
    {
        return $this->belongsTo(Anomaly::class, 'matched_anomaly_id');
    }
}
