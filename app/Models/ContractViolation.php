<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.4 — a recorded data-contract breach. Part of Platform\Governance.
 */
class ContractViolation extends Model
{
    protected $table = 'contract_violations';

    public const KIND_MISSING_COLUMNS = 'missing_columns';
    public const KIND_STALE           = 'stale';
    public const KIND_EMPTY           = 'empty';
    public const KIND_BELOW_MIN_ROWS  = 'below_min_rows';
    // W9 (WP9.2): learned-contract breaches.
    public const KIND_VOLUME_LOW      = 'volume_low';
    public const KIND_VOLUME_HIGH     = 'volume_high';
    public const KIND_SCHEMA_DRIFT    = 'schema_drift';
    public const KIND_LATE            = 'late';

    protected $fillable = [
        'tenant_id',
        'data_contract_id',
        'feed_key',
        'import_id',
        'kind',
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

    public function contract(): BelongsTo
    {
        return $this->belongsTo(DataContract::class, 'data_contract_id');
    }
}
