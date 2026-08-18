<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionRun extends Model
{
    // Status constants
    const STATUS_PENDING   = 'pending';
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_PARTIAL   = 'partial';

    // Data type constants
    const TYPE_SALES           = 'sales';
    const TYPE_INVENTORY       = 'inventory';
    const TYPE_PURCHASE_ORDERS = 'purchase_orders';
    const TYPE_PRODUCTS        = 'products';

    protected $fillable = [
        'tenant_id',
        'data_type',
        'source',
        'status',
        'filename',
        'file_size_bytes',
        'rows_processed',
        'rows_imported',
        'rows_failed',
        'rows_skipped',
        'started_at',
        'completed_at',
        'error_message',
        'error_sample',
        'import_id',
    ];

    protected $casts = [
        'error_sample'  => 'array',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->rows_failed === 0;
    }

    public function getDurationSeconds(): ?int
    {
        if (!$this->started_at || !$this->completed_at) return null;
        return $this->completed_at->diffInSeconds($this->started_at);
    }

    public function getSuccessRate(): ?float
    {
        if (!$this->rows_processed) return null;
        return round(($this->rows_imported / $this->rows_processed) * 100, 1);
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'success',
            self::STATUS_RUNNING   => 'info',
            self::STATUS_PARTIAL   => 'warning',
            self::STATUS_FAILED    => 'danger',
            default                => 'gray',
        };
    }
}
