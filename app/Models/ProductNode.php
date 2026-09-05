<?php

namespace App\Models;

use App\Models\Concerns\HasHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1.1 — a node in the canonical Product hierarchy (category → subcategory →
 * product). Part of Platform\Data. A leaf 'product' node links to the
 * operational products row via product_id; interior nodes (category/subcategory)
 * have no product_id. Tree behaviour is shared via {@see HasHierarchy}.
 */
class ProductNode extends Model
{
    use HasHierarchy;

    public const TYPE_CATEGORY    = 'category';
    public const TYPE_SUBCATEGORY = 'subcategory';
    public const TYPE_PRODUCT     = 'product';

    protected $fillable = [
        'tenant_id',
        'type',
        'code',
        'name',
        'parent_id',
        'product_id',
        'attributes',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'attributes'     => 'array',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Leaf link to the operational product row (null for interior nodes). */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }

    /**
     * The operational product ids under this node — the rollup primitive. For a
     * category node this is every product in the category; for a product node,
     * itself.
     *
     * @return array<int,int>
     */
    public function productIds(): array
    {
        return $this->leafRefIds('product_id', self::TYPE_PRODUCT);
    }
}
