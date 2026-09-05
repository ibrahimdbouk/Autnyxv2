<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1.5 — one feature value in the feature store: a named feature of an entity,
 * optionally dated (time-series) and versioned. Part of Platform\Features. The
 * `value` accessor returns the numeric value when present, else the text value.
 */
class FeatureValue extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'entity_type',
        'entity_key',
        'feature',
        'value_num',
        'value_text',
        'as_of',
        'version',
        'computed_at',
    ];

    protected $casts = [
        'value_num'   => 'decimal:6',
        'as_of'       => 'date',
        'version'     => 'integer',
        'computed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Numeric value if present, otherwise the text value. */
    public function getValueAttribute(): float|string|null
    {
        if ($this->value_num !== null) {
            return (float) $this->value_num;
        }

        return $this->value_text;
    }

    public function scopeForEntity(Builder $query, string $entityType, string $entityKey): Builder
    {
        return $query->where('entity_type', $entityType)->where('entity_key', $entityKey);
    }

    public function scopeFeature(Builder $query, string $feature): Builder
    {
        return $query->where('feature', $feature);
    }
}
