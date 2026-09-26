<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Assortment: whether a store carries a product, and since / until when. */
class AssortmentStoreRange extends Model
{
    public const SOURCE_INFERRED = 'inferred';
    public const SOURCE_LISTING  = 'listing';

    protected $fillable = [
        'tenant_id', 'store_id', 'sku', 'product_id', 'carried', 'source',
        'first_seen', 'last_seen', 'last_sale', 'last_in_stock', 'as_of_date',
    ];

    protected $casts = [
        'carried'       => 'boolean',
        'first_seen'    => 'date',
        'last_seen'     => 'date',
        'last_sale'     => 'date',
        'last_in_stock' => 'date',
        'as_of_date'    => 'date',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
