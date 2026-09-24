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
        'quarantined_rows',
        'duplicate_rows', // WP3.1: lines already loaded, skipped (not an error)
        // WP3.2: how the file is read (null → tenant default / detected).
        'date_format',
        'decimal_separator',
        'delimiter',
        'encoding',
        // WP3.3: a person confirmed the mapping (only then may it teach the memory).
        'mapping_confirmed_at',
        'mapping_confirmed_by',
        'process_phase', // WP3.6
        'process_cursor',
        'error_message',
    ];

    protected $casts = [
        'sample_rows' => 'array',
        'mapping_confirmed_at' => 'datetime',
    ];

    // Status constants
    const STATUS_UPLOADED             = 'uploaded';
    const STATUS_MAPPING_REVIEW       = 'mapping_review';
    const STATUS_IMPORTING            = 'importing';
    const STATUS_COMPLETED            = 'completed';
    const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    const STATUS_FAILED               = 'failed';
    const STATUS_ROLLED_BACK          = 'rolled_back';
    /** WP1.2 — an upload left in uploaded/mapping_review past the pending window. Resumable. */
    const STATUS_ABANDONED            = 'abandoned';
    /** WP3.6 (D6) — screened RED: nothing written until an admin promotes it. */
    const STATUS_HELD                 = 'held';

    /** WP3.6 — screen (quality decided, nothing written) → write. */
    const PHASE_SCREEN = 'screen';
    const PHASE_WRITE  = 'write';

    /** Data types whose rows are inserted (and therefore reversible via rollback). */
    const ROLLBACK_TYPES = [
        self::TYPE_SALES,
        self::TYPE_INVENTORY,
        self::TYPE_RETURNS,
        self::TYPE_PURCHASE_ORDERS,
    ];

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

    /**
     * Can this import be rolled back? Only insert-based datasets that have
     * actually been committed (not master data, which is upserted).
     */
    public function canRollback(): bool
    {
        return in_array($this->data_type, self::ROLLBACK_TYPES, true)
            && in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS], true);
    }

    public function isReadyToReview(): bool
    {
        return $this->status === self::STATUS_MAPPING_REVIEW;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS]);
    }

    public function isImporting(): bool
    {
        return $this->status === self::STATUS_IMPORTING;
    }

    /**
     * Percentage of rows processed so far (0–100), for the progress bar.
     */
    public function progressPercent(): int
    {
        if (! $this->total_rows) {
            return $this->isCompleted() ? 100 : 0;
        }

        $pct = min(100, ($this->process_cursor / $this->total_rows) * 100);

        // WP3.6: a screened import makes two passes — screen (0–50%), write (50–100%).
        return (int) round(match ($this->process_phase) {
            self::PHASE_SCREEN => $pct / 2,
            self::PHASE_WRITE  => 50 + $pct / 2,
            default            => $pct,
        });
    }
}
