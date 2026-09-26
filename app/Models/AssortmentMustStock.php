<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Assortment: a product never proposed for delisting (at one store, or every store). */
class AssortmentMustStock extends Model
{
    protected $table = 'assortment_must_stock';

    protected $fillable = ['tenant_id', 'sku', 'store_id', 'reason'];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
