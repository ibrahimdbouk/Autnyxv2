<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P3.3 — a physical lot of a SKU with expiry + cold-chain. Part of
 * Platform\Traceability. $table is explicit (not the inflector default) so a
 * model↔migration name mismatch can never recur (cf. INC-012).
 */
class Batch extends Model
{
    protected $table = 'batches';

    public const STATUS_ACTIVE      = 'active';
    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_RECALLED    = 'recalled';
    public const STATUS_DISPOSED    = 'disposed';
    public const STATUS_EXPIRED     = 'expired';

    protected $fillable = [
        'tenant_id',
        'sku',
        'product_id',
        'batch_code',
        'production_date',
        'expiry_date',
        'quantity',
        'cold_chain',
        'supplier_ref',
        'status',
    ];

    protected $casts = [
        'production_date' => 'date',
        'expiry_date'     => 'date',
        'quantity'        => 'float',
        'cold_chain'      => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(BatchMovement::class);
    }

    public function complianceEvents(): HasMany
    {
        return $this->hasMany(ComplianceEvent::class);
    }
}
