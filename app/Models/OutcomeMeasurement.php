<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OutcomeMeasurement — Feature 8
 *
 * A single deterministic metric measurement taken during an investigation's
 * post-action monitoring window. Reproducible via calculation_version + details.
 */
class OutcomeMeasurement extends Model
{
    const METRIC_SALES_REVENUE  = 'sales_revenue';
    const METRIC_SALES_UNITS    = 'sales_units';
    const METRIC_AVAILABILITY   = 'availability';
    const METRIC_RETURN_RATE    = 'return_rate';
    const METRIC_PO_FULFILLMENT = 'po_fulfillment';

    const METRIC_LABELS = [
        self::METRIC_SALES_REVENUE  => 'Sales Revenue',
        self::METRIC_SALES_UNITS    => 'Sales Units',
        self::METRIC_AVAILABILITY   => 'Availability',
        self::METRIC_RETURN_RATE    => 'Return Rate',
        self::METRIC_PO_FULFILLMENT => 'PO Fulfillment',
    ];

    // Outcome states (shared vocabulary with InvestigationOutcome)
    const STATE_NOT_MEASURED         = 'not_measured';
    const STATE_MONITORING           = 'monitoring';
    const STATE_NO_MATERIAL_CHANGE   = 'no_material_change';
    const STATE_PARTIAL_RECOVERY     = 'partial_recovery';
    const STATE_OBSERVED_RECOVERY    = 'observed_recovery';
    const STATE_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    protected $fillable = [
        'tenant_id',
        'investigation_id',
        'action_id',
        'metric_type',
        'baseline_value',
        'expected_value',
        'observed_value',
        'delta_value',
        'recovery_amount',
        'window_start',
        'window_end',
        'outcome_state',
        'calculation_version',
        'details',
        'computed_at',
    ];

    protected $casts = [
        'baseline_value'  => 'float',
        'expected_value'  => 'float',
        'observed_value'  => 'float',
        'delta_value'     => 'float',
        'recovery_amount' => 'float',
        'window_start'    => 'date',
        'window_end'      => 'date',
        'details'         => 'array',
        'computed_at'     => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    public function getMetricLabel(): string
    {
        return self::METRIC_LABELS[$this->metric_type] ?? $this->metric_type;
    }
}
