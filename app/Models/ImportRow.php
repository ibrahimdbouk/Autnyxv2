<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    protected $fillable = [
        'import_id',
        'tenant_id',
        'row_number',
        'raw_data',
        'mapped_data',
        'error_message',
        'status',
    ];

    protected $casts = [
        'raw_data'    => 'array',
        'mapped_data' => 'array',
    ];

    const STATUS_PENDING  = 'pending_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
