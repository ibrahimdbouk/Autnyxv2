<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.3 — one movement in a batch's chain of custody. Part of Platform\Traceability.
 */
class BatchMovement extends Model
{
    protected $table = 'batch_movements';

    public const TYPE_RECEIPT    = 'receipt';
    public const TYPE_TRANSFER   = 'transfer';
    public const TYPE_SALE       = 'sale';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_DISPOSAL   = 'disposal';
    public const TYPE_RETURN     = 'return';

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'movement_type',
        'from_location',
        'to_location',
        'quantity',
        'reference',
        'occurred_at',
    ];

    protected $casts = [
        'quantity'    => 'float',
        'occurred_at' => 'datetime',
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
