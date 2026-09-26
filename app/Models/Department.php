<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform core — a department (Grocery, Fresh, Non-Food…) or a section under
 * one (Grocery → Beverages). Shared by every app: tasks and store zones sit in
 * a department; a product belongs to the department whose name or mapped
 * categories match its own department / category / subcategory.
 */
class Department extends Model
{
    public const SOURCE_MANUAL   = 'manual';
    public const SOURCE_PRODUCTS = 'products';

    protected $fillable = ['tenant_id', 'parent_id', 'code', 'name', 'categories', 'sort', 'active', 'source'];

    protected $casts = [
        'categories' => 'array',
        'active'     => 'boolean',
        'sort'       => 'integer',
    ];

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

    public function zones(): HasMany
    {
        return $this->hasMany(StoreZone::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /** "Grocery › Beverages" for a section, the name for a department. */
    public function fullName(): string
    {
        return $this->parent ? $this->parent->name . ' › ' . $this->name : $this->name;
    }

    /**
     * The department (most specific first: a section, then a department) a
     * product falls in, matched case-insensitively on its subcategory,
     * category and department against each department's name and mapped
     * categories. Null when nothing matches.
     */
    public static function forProduct(int $tenantId, ?string $department, ?string $category = null, ?string $subcategory = null): ?self
    {
        $wanted = array_values(array_filter(array_map(
            fn ($v) => $v === null ? null : mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v))),
            [$subcategory, $category, $department]
        )));
        if ($wanted === []) {
            return null;
        }

        $all = self::where('tenant_id', $tenantId)->where('active', true)->orderByRaw('parent_id IS NULL')->orderBy('id')->get();
        foreach ($wanted as $w) {
            foreach ($all as $d) {
                $keys = array_map(fn ($c) => mb_strtolower(trim((string) $c)), array_merge([$d->name], (array) ($d->categories ?? [])));
                if (in_array($w, $keys, true)) {
                    return $d;
                }
            }
        }

        return null;
    }
}
