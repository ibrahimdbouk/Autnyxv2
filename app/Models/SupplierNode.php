<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveDating;
use App\Models\Concerns\HasHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1.1 — a node in the canonical Supplier hierarchy (group → supplier). Part of
 * Platform\Data. Interior 'group' nodes are derived from supplier business type;
 * a leaf 'supplier' node links to the operational suppliers row via supplier_id.
 * Tree behaviour is shared via {@see HasHierarchy}.
 */
class SupplierNode extends Model
{
    use HasHierarchy;
    use HasEffectiveDating;

    public const TYPE_GROUP    = 'group';
    public const TYPE_SUPPLIER = 'supplier';

    protected $fillable = [
        'tenant_id',
        'type',
        'code',
        'name',
        'parent_id',
        'supplier_id',
        'attributes',
        'effective_from',
        'effective_to',
        'recorded_at',
    ];

    protected $casts = [
        'attributes'     => 'array',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'recorded_at'    => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Leaf link to the operational supplier row (null for interior nodes). */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     * The operational supplier ids under this node — the rollup primitive. For a
     * group node this is every supplier in the group; for a supplier node, itself.
     *
     * @return array<int,int>
     */
    public function supplierIds(): array
    {
        return $this->leafRefIds('supplier_id', self::TYPE_SUPPLIER);
    }
}
