<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Normalises the content of a field (payment method, return reason, UoM synonyms…).
 * See claude/data-quality-firewall.md.
 */
class ValueMap extends Model
{
    protected $fillable = ['tenant_id', 'data_type', 'field', 'from_value', 'to_value'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
