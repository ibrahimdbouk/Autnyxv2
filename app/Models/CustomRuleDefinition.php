<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P3.2 — a tenant-defined rule (custom exception condition). Part of
 * Platform\Extensibility. `condition` is a safe boolean AST.
 */
class CustomRuleDefinition extends Model
{
    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'tenant_id',
        'key',
        'label',
        'condition',
        'formula',
        'impact_formula',
        'impact',
        'value_type',
        'description',
        'severity',
        'objective',
        'active',
    ];

    protected $casts = [
        'condition' => 'array',
        'impact'    => 'array',
        'active'    => 'boolean',
    ];

    /** W10: how a hit maps onto an anomaly's severity. */
    public const ANOMALY_SEVERITY = [
        self::SEVERITY_INFO     => 'low',
        self::SEVERITY_WARNING  => 'medium',
        self::SEVERITY_CRITICAL => 'high',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
