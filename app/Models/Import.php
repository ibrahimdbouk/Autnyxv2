<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'original_filename',
        'disk',
        'path',
        'data_type',
        'status',
        'sample_rows',
        'total_rows',
        'imported_rows',
        'failed_rows',
        'error_message',
    ];

    protected $casts = [
        'sample_rows' => 'array',
    ];

    // Status constants
    const STATUS_UPLOADED             = 'uploaded';
    const STATUS_MAPPING_REVIEW       = 'mapping_review';
    const STATUS_IMPORTING            = 'importing';
    const STATUS_COMPLETED            = 'completed';
    const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    const STATUS_FAILED               = 'failed';

    // Data type constants
    const TYPE_SALES        = 'sales_transactions';
    const TYPE_INVENTORY    = 'inventory_levels';
    const TYPE_PRODUCTS     = 'products';
    const TYPE_PURCHASE_ORDERS = 'purchase_orders';
    // Master / reference data (M24 — completes the import surface)
    const TYPE_STORES       = 'stores';
    const TYPE_SUPPLIERS    = 'suppliers';
    const TYPE_USERS        = 'users';
    const TYPE_RETURNS      = 'returns';

    public static function dataTypeLabels(): array
    {
        return [
            self::TYPE_SALES           => 'Sales Transactions',
            self::TYPE_INVENTORY       => 'Inventory Levels',
            self::TYPE_PRODUCTS        => 'Products',
            self::TYPE_PURCHASE_ORDERS => 'Purchase Orders',
            self::TYPE_STORES          => 'Stores / Locations',
            self::TYPE_SUPPLIERS       => 'Suppliers',
            self::TYPE_USERS           => 'Users (account setup)',
            self::TYPE_RETURNS         => 'Returns / Refunds',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function columnMaps(): HasMany
    {
        return $this->hasMany(ImportColumnMap::class)->orderBy('sort_order');
    }

    public function failedRows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function getDataTypeLabelAttribute(): string
    {
        return self::dataTypeLabels()[$this->data_type] ?? $this->data_type;
    }

    public function isReadyToReview(): bool
    {
        return $this->status === self::STATUS_MAPPING_REVIEW;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS]);
    }
}
