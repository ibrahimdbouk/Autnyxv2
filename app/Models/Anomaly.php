<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Anomaly extends Model
{
    const SEVERITY_LOW    = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH   = 'high';

    protected $fillable = [
        'tenant_id',
        'rule_type',
        'severity',
        'sku',
        'store_id',
        'product_id',
        'description',
        'context',
        'detected_at',
        'dismissed_at',
        'dismissed_by',
    ];

    protected $casts = [
        'context'      => 'array',
        'detected_at'  => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function dismissedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    public function getRuleLabel(): string
    {
        return AnomalySetting::RULES[$this->rule_type]['label'] ?? $this->rule_type;
    }
}
