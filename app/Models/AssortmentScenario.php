<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assortment v1.5 Phase 3 — a saved what-if for one store × category in the
 * Decision Studio: the inputs, and the result as simulated when it was saved.
 * A scenario never changes anything; making it the plan does that.
 */
class AssortmentScenario extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'category', 'name', 'preset', 'inputs', 'result', 'as_of_date', 'created_by'];

    protected $casts = [
        'inputs'     => 'array',
        'result'     => 'array',
        'as_of_date' => 'date',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
