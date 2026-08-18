<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationEntity extends Model
{
    // entity_type values
    const TYPE_SKU      = 'sku';
    const TYPE_STORE    = 'store';
    const TYPE_SUPPLIER = 'supplier';
    const TYPE_CATEGORY = 'category';
    const TYPE_RULE     = 'rule';

    protected $fillable = [
        'investigation_id',
        'anomaly_id',
        'entity_type',
        'entity_key',
        'store_id',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function anomaly(): BelongsTo
    {
        return $this->belongsTo(Anomaly::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
