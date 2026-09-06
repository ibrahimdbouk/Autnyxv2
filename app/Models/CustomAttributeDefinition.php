<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.2 — a tenant-declared custom dimension on a canonical entity. Part of
 * Platform\Extensibility. Values live in {@see EntityAttributeValue}; a value may
 * only be stored against a dimension declared here (the governance gate).
 */
class CustomAttributeDefinition extends Model
{
    public const TYPE_STRING  = 'string';
    public const TYPE_NUMBER  = 'number';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_DATE    = 'date';

    protected $fillable = [
        'tenant_id',
        'entity_type',
        'key',
        'label',
        'data_type',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
