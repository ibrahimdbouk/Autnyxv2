<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P4.1 — a tenant guardrail. Part of Platform\Policy. `condition` is a safe
 * boolean AST (P3.2 Evaluator); when it evaluates true against an action-intent,
 * the guardrail is violated and `effect` applies. $table explicit (INC-012 guard).
 */
class PolicyRule extends Model
{
    protected $table = 'policy_rules';

    public const EFFECT_BLOCK            = 'block';
    public const EFFECT_WARN             = 'warn';
    public const EFFECT_REQUIRE_APPROVAL = 'require_approval';

    protected $fillable = [
        'tenant_id',
        'key',
        'label',
        'effect',
        'condition',
        'active',
    ];

    protected $casts = [
        'condition' => 'array',
        'active'    => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
