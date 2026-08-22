<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'tenant_id',
        'import_id',
        'product_id',
        'supplier_id',
        'po_number',
        'supplier',      // legacy free-text — kept until supplier_id is fully back-populated
        'sku',
        'qty_ordered',
        'qty_received',
        'unit_cost',
        'order_date',
        'expected_date',
        'received_date',
    ];

    protected $casts = [
        'qty_ordered'   => 'decimal:4',
        'qty_received'  => 'decimal:4',
        'unit_cost'     => 'decimal:4',
        'order_date'    => 'date',
        'expected_date' => 'date',
        'received_date' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
