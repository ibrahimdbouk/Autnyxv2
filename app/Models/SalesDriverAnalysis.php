<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Root Cause drivers — one stored breakdown (what moved sales and why) for a scope and pair of periods. */
class SalesDriverAnalysis extends Model
{
    protected $table = 'driver_analyses';

    protected $fillable = ['tenant_id', 'scope_key', 'kind', 'period_from', 'period_to', 'comparison_from', 'comparison_to',
        'comparison_kind', 'change', 'unexplained', 'result', 'version', 'computed_at'];

    protected $casts = [
        'result'          => 'array',
        'period_from'     => 'date',
        'period_to'       => 'date',
        'comparison_from' => 'date',
        'comparison_to'   => 'date',
        'computed_at'     => 'datetime',
        'change'          => 'float',
        'unexplained'     => 'float',
    ];
}
