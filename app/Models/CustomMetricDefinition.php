<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.2 — a tenant-defined KPI (stored, not code-registered). Part of
 * Platform\Extensibility. `expression` is a safe AST evaluated by the Evaluator.
 */
class CustomMetricDefinition extends Model
{
    protected $fillable = [
        'tenant_id',
        'key',
        'label',
        'unit',
        'description',
        'expression',
        'objective',
        'version',
        'active',
    ];

    protected $casts = [
        'expression' => 'array',
        'version'    => 'integer',
        'active'     => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
