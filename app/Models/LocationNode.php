<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * P1.1 — a node in the canonical Location hierarchy (banner → region → DC →
 * store). Part of Platform\Data: the shared operational read model every app
 * rolls up / drills down through, independent of any one app's flat tables.
 *
 * A leaf 'store' node links to the operational stores row via store_id; interior
 * nodes (region/banner/DC) have no store_id. Integrity of the parent edge is
 * enforced here rather than at the DB level so the tree stays portable and
 * re-parentable (the P1.3 bitemporal layer will version those edges).
 */
class LocationNode extends Model
{
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
    ];

    protected $casts = [
        'attributes'     => 'array',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    // ── Relations ────────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Leaf link to the operational store row (null for interior nodes). */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }

    // ── Hierarchy walking ────────────────────────────────────────────────────

    /**
     * Ancestor nodes from immediate parent up to the root (leaf → root order).
     * Cycle-guarded.
     */
    public function ancestors(): Collection
    {
        $chain = collect();
        $node  = $this->parent;
        $guard = 0;

        while ($node && $guard++ < 100) {
            $chain->push($node);
            $node = $node->parent;
        }

        return $chain;
    }

    /**
     * All descendant node ids beneath this node (breadth-first, cycle-guarded).
     *
     * @return array<int,int>
     */
    public function descendantIds(): array
    {
        $found    = [];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $children = static::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $children = array_values(array_diff($children, $found, [$this->id]));
            if ($children === []) {
                break;
            }

            $found    = array_merge($found, $children);
            $frontier = $children;
        }

        return $found;
    }

    /**
     * The operational store ids under this node — the rollup primitive. For a
     * region node this is every store in the region; for a store node, itself.
     *
     * @return array<int,int>
     */
    public function storeIds(): array
    {
        $nodeIds = array_merge([$this->id], $this->descendantIds());

        return static::query()
            ->whereIn('id', $nodeIds)
            ->where('type', self::TYPE_STORE)
            ->whereNotNull('store_id')
            ->pluck('store_id')
            ->all();
    }
}
