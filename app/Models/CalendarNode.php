<?php

namespace App\Models;

use App\Models\Concerns\HasHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1.1 — a node in the canonical Calendar / time dimension (year → quarter →
 * month → day). Part of Platform\Data. Tree walking is shared via
 * {@see HasHierarchy}; because the leaf carries a date rather than an operational
 * ref, rollup is expressed as the set/range of dates under a node.
 */
class CalendarNode extends Model
{
    use HasHierarchy;

    public const TYPE_YEAR    = 'year';
    public const TYPE_QUARTER = 'quarter';
    public const TYPE_MONTH   = 'month';
    public const TYPE_DAY     = 'day';

    protected $fillable = [
        'tenant_id',
        'type',
        'code',
        'name',
        'parent_id',
        'date',
        'attributes',
    ];

    protected $casts = [
        'date'       => 'date',
        'attributes' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * All calendar dates (Y-m-d) under this node — the rollup primitive. For a
     * month this is its days; for a day, itself.
     *
     * @return array<int,string>
     */
    public function dates(): array
    {
        $nodeIds = array_merge([$this->getKey()], $this->descendantIds());

        return static::query()
            ->whereIn('id', $nodeIds)
            ->where('type', self::TYPE_DAY)
            ->whereNotNull('date')
            ->orderBy('date')
            ->get(['id', 'date'])
            ->map(fn ($node) => $node->date->format('Y-m-d'))
            ->all();
    }

    /**
     * The inclusive [start, end] date span this node covers, or null if empty.
     *
     * @return array{0:string,1:string}|null
     */
    public function dateRange(): ?array
    {
        $dates = $this->dates();
        if ($dates === []) {
            return null;
        }

        return [reset($dates), end($dates)];
    }
}
