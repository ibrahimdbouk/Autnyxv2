<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'lead_time_days',
        'contact_email',
        'contact_phone',
        'type',
        'specialization',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    // NOTE: products are NOT linked to suppliers by a direct FK — the products
    // table has only a legacy free-text `supplier` column, not `supplier_id`.
    // A supplier's products are derived through its purchase orders
    // (purchase_orders.supplier_id + purchase_orders.product_id). Do not add a
    // hasMany(Product::class) here — it resolves to products.supplier_id, which
    // does not exist, and throws a SQL error wherever it is queried.
}
