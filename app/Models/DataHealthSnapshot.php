<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DataHealthSnapshot — Feature 4
 *
 * Cached, deterministically-computed health grade for one dataset in one tenant.
 */
class DataHealthSnapshot extends Model
{
    const STATUS_HEALTHY  = 'healthy';
    const STATUS_WARNING  = 'warning';
    const STATUS_CRITICAL = 'critical';
    const STATUS_NO_DATA  = 'no_data';

    const DATASET_SALES           = 'sales';
    const DATASET_INVENTORY       = 'inventory';
    const DATASET_PRODUCTS        = 'products';
    const DATASET_STORES          = 'stores';
    const DATASET_PURCHASE_ORDERS = 'purchase_orders';
    const DATASET_SUPPLIERS       = 'suppliers';

    const DATASET_LABELS = [
        self::DATASET_SALES           => 'Sales',
        self::DATASET_INVENTORY       => 'Inventory',
        self::DATASET_PRODUCTS        => 'Products',
        self::DATASET_STORES          => 'Stores',
        self::DATASET_PURCHASE_ORDERS => 'Purchase Orders',
        self::DATASET_SUPPLIERS       => 'Suppliers',
    ];

    protected $fillable = [
        'tenant_id',
        'dataset',
        'status',
        'score',
        'last_ingested_at',
        'last_record_at',
        'freshness_hours',
        'completeness_pct',
        'validity_pct',
        'records_received',
        'records_accepted',
        'records_rejected',
        'warnings',
        'metrics',
        'computed_at',
    ];

    protected $casts = [
        'score'            => 'float',
        'completeness_pct' => 'float',
        'validity_pct'     => 'float',
        'freshness_hours'  => 'integer',
        'records_received' => 'integer',
        'records_accepted' => 'integer',
        'records_rejected' => 'integer',
        'warnings'         => 'array',
        'metrics'          => 'array',
        'last_ingested_at' => 'datetime',
        'last_record_at'   => 'datetime',
        'computed_at'      => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getDatasetLabel(): string
    {
        return self::DATASET_LABELS[$this->dataset] ?? ucfirst($this->dataset);
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_HEALTHY  => 'success',
            self::STATUS_WARNING  => 'warning',
            self::STATUS_CRITICAL => 'danger',
            default               => 'gray',
        };
    }

    public function hasWarnings(): bool
    {
        return is_array($this->warnings) && count($this->warnings) > 0;
    }
}
