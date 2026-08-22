<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'import_id',
        'store_id',
        'product_id',
        'sku',
        'location',
        'on_hand_qty',
        'reorder_point',
        'as_of_date',
    ];

    protected $casts = [
        'on_hand_qty'   => 'decimal:4',
        'reorder_point' => 'decimal:4',
        'as_of_date'    => 'date',
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
