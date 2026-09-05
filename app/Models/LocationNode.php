<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveDating;
use App\Models\Concerns\HasHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1.1 — a node in the canonical Location hierarchy (banner → region → DC →
 * store). Part of Platform\Data: the shared operational read model every app
 * rolls up / drills down through, independent of any one app's flat tables.
 *
 * A leaf 'store' node links to the operational stores row via store_id; interior
 * nodes (region/banner/DC) have no store_id. Tree behaviour is shared with the
 * other canonical hierarchies via {@see HasHierarchy}.
 */
class LocationNode extends Model
{
    use HasHierarchy;
    use HasEffectiveDating;

    public const TYPE_BANNER = 'banner';
    public const TYPE_REGION = 'region';
    public const TYPE_DC     = 'dc';
    public const TYPE_STORE  = 'store';

    protected $fillable = [
        'tenant_id',
        'type',
        'code',
        'name',
        'parent_id',
        'store_id',
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

    /** Leaf link to the operational store row (null for interior nodes). */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
     * The operational store ids under this node — the rollup primitive. For a
     * region node this is every store in the region; for a store node, itself.
     *
     * @return array<int,int>
     */
    public function storeIds(): array
    {
        return $this->leafRefIds('store_id', self::TYPE_STORE);
    }
}
