<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.3 — a compliance event against a batch (or tenant-wide). Part of
 * Platform\Traceability.
 */
class ComplianceEvent extends Model
{
    protected $table = 'compliance_events';

    public const TYPE_TEMPERATURE_EXCURSION = 'temperature_excursion';
    public const TYPE_EXPIRY_BREACH         = 'expiry_breach';
    public const TYPE_RECALL                = 'recall';
    public const TYPE_QUARANTINE            = 'quarantine';
    public const TYPE_DISPOSAL              = 'disposal';
    public const TYPE_OTHER                 = 'other';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'event_type',
        'severity',
        'detail',
        'occurred_at',
        'resolved_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
