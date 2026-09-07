<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.4 — one persisted scorecard row (a dimension's quality at a point in time).
 * Part of Platform\Evaluation. $table explicit (INC-012 guard).
 */
class EvaluationSnapshot extends Model
{
    protected $table = 'evaluation_snapshots';

    public const DIM_OVERALL     = 'overall';
    public const DIM_INTENT_TYPE = 'intent_type';
    public const DIM_OBJECTIVE   = 'objective';

    protected $fillable = [
        'tenant_id',
        'dimension',
        'dim_key',
        'period',
        'n',
        'resolved',
        'adoption_rate',
        'success_rate',
        'avg_realization',
        'avg_confidence',
        'calibration_gap',
        'captured_at',
    ];

    protected $casts = [
        'n'               => 'integer',
        'resolved'        => 'integer',
        'adoption_rate'   => 'float',
        'success_rate'    => 'float',
        'avg_realization' => 'float',
        'avg_confidence'  => 'float',
        'calibration_gap' => 'float',
        'captured_at'     => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
