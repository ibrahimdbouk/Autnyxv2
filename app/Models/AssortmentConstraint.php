<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v2.2 — a commercial deal the range must keep: a line that stays, or the
 * fewest products of a brand or supplier (in a category), at one store, every
 * store of a format, or the whole chain, until a date. The rules treat it as a
 * hard limit; `cost` is what keeping it was worth in the last run.
 */
class AssortmentConstraint extends Model
{
    public const KEEP = 'keep';
    public const BRAND_MIN = 'brand_min';
    public const SUPPLIER_MIN = 'supplier_min';

    public const KINDS = [
        self::KEEP         => 'Keep a line',
        self::BRAND_MIN    => 'Brand floor',
        self::SUPPLIER_MIN => 'Supplier floor',
    ];

    protected $fillable = ['tenant_id', 'kind', 'sku', 'brand', 'supplier', 'category', 'store_id', 'format', 'min_count', 'until', 'note', 'cost'];

    protected $casts = [
        'until'     => 'date',
        'cost'      => 'array',
        'min_count' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function what(): string
    {
        return match ($this->kind) {
            self::KEEP => (string) $this->sku,
            self::BRAND_MIN => 'At least ' . $this->min_count . ' ' . $this->brand . ($this->category ? ' in ' . $this->category : ''),
            default => 'At least ' . $this->min_count . ' from ' . $this->supplier . ($this->category ? ' in ' . $this->category : ''),
        };
    }

    public function place(): string
    {
        return $this->store?->name ?? ($this->format ? 'Every ' . \Illuminate\Support\Str::headline($this->format) . ' store' : 'Every store');
    }
}
