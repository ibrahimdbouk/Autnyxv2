<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued (store, SKU) subject whose data changed since the last detection run.
 * Populated by DirtyKeyRecorder (imports/rollbacks now; baseline/profile shifts
 * later). Consumed by the incremental detection run (Slice 2+).
 *
 * See claude/incremental-detection-design.md.
 */
class DetectionDirtyKey extends Model
{
    public const REASON_IMPORT   = 'import';
    public const REASON_ROLLBACK = 'rollback';
    public const REASON_BASELINE = 'baseline';
    public const REASON_PROFILE  = 'profile';
    public const REASON_MANUAL   = 'manual';

    // Append-only queue — a single created_at, no updated_at.
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'sku',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
