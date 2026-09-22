<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-import data-quality summary from the firewall. See
 * claude/data-quality-firewall.md.
 */
class ImportQuality extends Model
{
    protected $table = 'import_quality';

    protected $fillable = [
        'tenant_id', 'import_id', 'data_type',
        'rows_seen', 'rows_promoted', 'rows_quarantined', 'rows_cleansed',
        'reason_counts', 'column_profile', 'file_fingerprint', 'is_duplicate_file',
    ];

    protected $casts = [
        'reason_counts'     => 'array',
        'column_profile'    => 'array',
        'is_duplicate_file' => 'boolean',
        'rows_seen'         => 'integer',
        'rows_promoted'     => 'integer',
        'rows_quarantined'  => 'integer',
        'rows_cleansed'     => 'integer',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /** 0–100 — share of seen rows that promoted clean, minus a light cleansing penalty. */
    public function score(): int
    {
        $seen = max(1, (int) $this->rows_seen);
        $promoted = (int) $this->rows_promoted;
        $base = $promoted / $seen;                       // clean-promote rate
        $penalty = 0.15 * ((int) $this->rows_cleansed / $seen); // heavy repair drags the score
        return (int) round(max(0, min(1, $base - $penalty)) * 100);
    }

    public function scoreColor(): string
    {
        $s = $this->score();
        return $s >= 90 ? 'success' : ($s >= 70 ? 'warning' : 'danger');
    }
}
