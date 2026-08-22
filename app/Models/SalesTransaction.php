<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'import_id',
        'store_id',
        'product_id',
        'transaction_id',
        'date',
        'sku',
        'location',
        'quantity',
        'unit_price',
        'total_amount',
        'discount',
        'payment_method',
    ];

    protected $casts = [
        'date'         => 'date',
        'quantity'     => 'decimal:4',
        'unit_price'   => 'decimal:4',
        'total_amount' => 'decimal:4',
        'discount'     => 'decimal:4',
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
