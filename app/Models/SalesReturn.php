<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SalesReturn — a returns/refunds record: store, SKU, quantity, value, date, reason.
 */
class SalesReturn extends Model
{
    protected $table = 'sales_returns';

    protected $fillable = [
        'tenant_id',
        'import_id',
        'store_id',
        'product_id',
        'return_id',
        'date',
        'sku',
        'location',
        'quantity',
        'value',
        'reason',
    ];

    protected $casts = [
        'date'     => 'date',
        'quantity' => 'decimal:4',
        'value'    => 'decimal:4',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
