<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.8 — a tenant's autonomy setting for orchestration. Part of
 * Platform\Orchestration. $table explicit (INC-012 guard).
 */
class AutonomyPolicy extends Model
{
    protected $table = 'autonomy_policies';

    public const LEVEL_ADVISE  = 'advise';
    public const LEVEL_APPROVE = 'approve';
    public const LEVEL_AUTO    = 'auto';

    public const DEFAULT_KEY            = '*';
    public const DEFAULT_LEVEL          = self::LEVEL_ADVISE; // safest default
    public const DEFAULT_MIN_CONFIDENCE = 0.8;

    protected $fillable = [
        'tenant_id',
        'intent_type',
        'level',
        'min_confidence',
        'active',
    ];

    protected $casts = [
        'min_confidence' => 'float',
        'active'         => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
