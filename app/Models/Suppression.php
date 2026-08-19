<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suppression — Feature 6
 *
 * Prevents a known-noisy pattern from surfacing for a scope + period. Enforced
 * at surfacing/correlation time; anomalies are still detected and recorded.
 */
class Suppression extends Model
{
    const SCOPE_RULE           = 'rule';
    const SCOPE_RULE_STORE     = 'rule_store';
    const SCOPE_RULE_SKU       = 'rule_sku';
    const SCOPE_RULE_STORE_SKU = 'rule_store_sku';

    const SCOPE_LABELS = [
        self::SCOPE_RULE           => 'Rule (all)',
        self::SCOPE_RULE_STORE     => 'Rule + Store',
        self::SCOPE_RULE_SKU       => 'Rule + SKU',
        self::SCOPE_RULE_STORE_SKU => 'Rule + Store + SKU',
    ];

    const REASON_KNOWN_ISSUE            = 'known_issue';
    const REASON_PLANNED_PROMOTION      = 'planned_promotion';
    const REASON_STORE_CLOSURE          = 'store_closure';
    const REASON_MAINTENANCE            = 'maintenance';
    const REASON_KNOWN_SUPPLIER_PROBLEM = 'known_supplier_problem';
    const REASON_DATA_ISSUE             = 'data_issue';
    const REASON_FALSE_POSITIVE         = 'false_positive';
    const REASON_OTHER                  = 'other';

    const REASON_LABELS = [
        self::REASON_KNOWN_ISSUE            => 'Known issue',
        self::REASON_PLANNED_PROMOTION      => 'Planned promotion',
        self::REASON_STORE_CLOSURE          => 'Store closure',
        self::REASON_MAINTENANCE            => 'Maintenance',
        self::REASON_KNOWN_SUPPLIER_PROBLEM => 'Known supplier problem',
        self::REASON_DATA_ISSUE             => 'Data issue',
        self::REASON_FALSE_POSITIVE         => 'False positive',
        self::REASON_OTHER                  => 'Other',
    ];

    protected $fillable = [
        'tenant_id',
        'scope_type',
        'rule_type',
        'sku',
        'store_id',
        'reason',
        'notes',
        'starts_at',
        'expires_at',
        'active',
        'match_count',
        'last_matched_at',
        'created_by',
        'ended_by',
        'ended_at',
    ];

    protected $casts = [
        'active'          => 'boolean',
        'match_count'     => 'integer',
        'starts_at'       => 'datetime',
        'expires_at'      => 'datetime',
        'last_matched_at' => 'datetime',
        'ended_at'        => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->active) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }

    public function getScopeLabel(): string
    {
        return self::SCOPE_LABELS[$this->scope_type] ?? $this->scope_type;
    }

    public function getReasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? ucfirst(str_replace('_', ' ', $this->reason));
    }

    public function getScopeDescription(): string
    {
        $parts = [$this->rule_type];
        if ($this->store_id) {
            $parts[] = 'store #' . $this->store_id;
        }
        if ($this->sku) {
            $parts[] = 'SKU ' . $this->sku;
        }
        return implode(' · ', $parts);
    }
}
