<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Shared self-referencing tree behaviour for the canonical Platform\Data
 * hierarchies (Location, Product, Supplier). Each using model has: a `parent_id`
 * self edge, a `type` column whose leaf tier links to an operational row, and
 * effective-dating columns. The tree edge is application-enforced (no DB self-FK)
 * so nodes stay portable and re-parentable.
 */
trait HasHierarchy
{
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /** Ancestor nodes from immediate parent up to the root (leaf → root). Cycle-guarded. */
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
        $frontier = [$this->getKey()];

        while ($frontier !== []) {
            $children = static::query()
                ->whereIn('parent_id', $frontier)
                ->pluck($this->getKeyName())
                ->all();

            $children = array_values(array_diff($children, $found, [$this->getKey()]));
            if ($children === []) {
                break;
            }

            $found    = array_merge($found, $children);
            $frontier = $children;
        }

        return $found;
    }

    /**
     * The operational leaf-row ids under this node — the rollup primitive. For a
     * Location region this is every store below it; for a Product category, every
     * product below it.
     *
     * @return array<int,int>
     */
    public function leafRefIds(string $refColumn, string $leafType): array
    {
        $nodeIds = array_merge([$this->getKey()], $this->descendantIds());

        return static::query()
            ->whereIn($this->getKeyName(), $nodeIds)
            ->where('type', $leafType)
            ->whereNotNull($refColumn)
            ->pluck($refColumn)
            ->all();
    }
}
