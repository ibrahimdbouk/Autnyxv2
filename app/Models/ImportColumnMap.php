<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportColumnMap extends Model
{
    protected $fillable = [
        'import_id',
        'source_header',
        'target_field',
        'confidence',
        'reasoning',
        'is_confirmed',
        'is_skipped',
        'sort_order',
    ];

    protected $casts = [
        'confidence'   => 'float',
        'is_confirmed' => 'boolean',
        'is_skipped'   => 'boolean',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function getConfidenceLabelAttribute(): string
    {
        return match (true) {
            $this->confidence >= 0.85 => 'High',
            $this->confidence >= 0.5  => 'Medium',
            default                   => 'Low',
        };
    }

    public function getConfidenceColorAttribute(): string
    {
        return match (true) {
            $this->confidence >= 0.85 => 'success',
            $this->confidence >= 0.5  => 'warning',
            default                   => 'danger',
        };
    }
}
