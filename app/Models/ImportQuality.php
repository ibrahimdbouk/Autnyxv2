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

    public const STATE_GREEN = 'green'; // detection ready
    public const STATE_AMBER = 'amber'; // auto-promoted, exceptions to review
    public const STATE_RED   = 'red';   // blocked
    /** WP3.6 — an identical file already loaded: skipped, and ignored by readiness. */
    public const STATE_DUPLICATE = 'duplicate';

    protected $fillable = [
        'tenant_id', 'import_id', 'data_type', 'source',
        'rows_seen', 'rows_promoted', 'rows_quarantined', 'rows_cleansed',
        'reason_counts', 'column_profile', 'file_fingerprint', 'is_duplicate_file',
        'state', 'decision', 'blocked',
        'overridden_by', 'overridden_at', // WP3.6: a RED batch promoted by an admin
        'pii_columns', // W9 (WP9.5)
    ];

    protected $casts = [
        'reason_counts'     => 'array',
        'column_profile'    => 'array',
        'pii_columns'       => 'array',
        'is_duplicate_file' => 'boolean',
        'blocked'           => 'boolean',
        'rows_seen'         => 'integer',
        'rows_promoted'     => 'integer',
        'rows_quarantined'  => 'integer',
        'rows_cleansed'     => 'integer',
        'overridden_at'     => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * Required by Filament multi-tenancy: the panel auto-scopes tenant-aware
     * resources through this ownership relationship. Without it, rendering the
     * Import Quality table 500s ("model has no relationship named [tenant]").
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * 0–100 — share of seen rows that promoted clean, minus a light cleansing penalty.
     * NB: not named `score()` — a bare method name collides with Eloquent's
     * relationship resolution (it would be called as a relation and throw).
     */
    public function qualityScore(): int
    {
        $seen = max(1, (int) $this->rows_seen);
        $promoted = (int) $this->rows_promoted;
        $base = $promoted / $seen;                       // clean-promote rate
        $penalty = 0.15 * ((int) $this->rows_cleansed / $seen); // heavy repair drags the score
        return (int) round(max(0, min(1, $base - $penalty)) * 100);
    }

    public function qualityColor(): string
    {
        $s = $this->qualityScore();
        return $s >= 90 ? 'success' : ($s >= 70 ? 'warning' : 'danger');
    }

    public function stateColor(): string
    {
        return match ($this->state) {
            self::STATE_GREEN => 'success',
            self::STATE_AMBER => 'warning',
            self::STATE_DUPLICATE => 'gray',
            default           => 'danger',
        };
    }
}
