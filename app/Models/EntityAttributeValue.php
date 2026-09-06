<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.2 — one custom-dimension value for one entity (the EAV row). Part of
 * Platform\Extensibility. `value` is stored as text and coerced per the
 * dimension's declared data_type on read.
 */
class EntityAttributeValue extends Model
{
    protected $fillable = [
        'tenant_id',
        'entity_type',
        'entity_id',
        'attribute_key',
        'value',
    ];

    protected $casts = [
        'entity_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
