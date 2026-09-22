<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row the Data Quality Firewall rejected before it could reach the canonical
 * tables. See claude/data-quality-firewall.md.
 */
class QuarantinedRow extends Model
{
    public const STATUS_OPEN     = 'open';
    public const STATUS_SKIPPED  = 'skipped';   // user chose to discard
    public const STATUS_PROMOTED = 'promoted';  // user forced it through
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'tenant_id', 'import_id', 'data_type', 'row_number',
        'raw_data', 'cleansed_data', 'reason_code', 'severity', 'message', 'status',
    ];

    protected $casts = [
        'raw_data'      => 'array',
        'cleansed_data' => 'array',
        'row_number'    => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
