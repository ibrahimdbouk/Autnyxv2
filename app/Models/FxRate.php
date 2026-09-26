<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** W13 — units of the tenant's currency per 1 unit of `currency`, from `valid_from`. */
class FxRate extends Model
{
    protected $fillable = ['tenant_id', 'currency', 'valid_from', 'rate', 'source'];

    protected $casts = ['valid_from' => 'date', 'rate' => 'float'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
