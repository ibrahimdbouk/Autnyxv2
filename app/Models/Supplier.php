<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        // Hardening (2026-09-23) — location + commercial terms.
        'country',
        'region',
        'city',
        'currency',
        'payment_terms',
        'min_order_value',
        'website',
        'status',
    ];

    protected $casts = [
        'min_order_value' => 'decimal:2',
    ];

    /** WP6.5: the canonical hierarchy follows (once per tenant, at the end of the request). */
    protected static function booted(): void
    {
        static::saved(fn (self $m) => \App\Services\Platform\HierarchySync::later((int) $m->tenant_id));
        static::deleted(fn (self $m) => \App\Services\Platform\HierarchySync::later((int) $m->tenant_id));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** This supplier's leaf node in the canonical Supplier hierarchy (P1.1). */
    public function supplierNode(): HasOne
    {
        return $this->hasOne(SupplierNode::class);
    }

    // NOTE: products are NOT linked to suppliers by a direct FK — the products
    // table has only a legacy free-text `supplier` column, not `supplier_id`.
    // A supplier's products are derived through its purchase orders
    // (purchase_orders.supplier_id + purchase_orders.product_id). Do not add a
    // hasMany(Product::class) here — it resolves to products.supplier_id, which
    // does not exist, and throws a SQL error wherever it is queried.
}
