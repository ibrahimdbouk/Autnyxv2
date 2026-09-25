<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * W9 (WP9.3) — one semantic / cross-dataset data-quality finding: a check
 * about one subject (a store, a day, a data type, the whole tenant). Each run
 * re-sees or resolves it; `occurrences` counts the runs that saw it.
 */
class DqFinding extends Model
{
    protected $table = 'dq_findings';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_INFO     = 'info';

    protected $fillable = [
        'tenant_id', 'check', 'dataset', 'severity', 'subject_key', 'subject', 'message', 'metrics',
        'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at',
    ];

    protected $casts = [
        'metrics'       => 'array',
        'occurrences'   => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
        'resolved_at'   => 'datetime',
    ];

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    public function severityRank(): int
    {
        return match ($this->severity) {
            self::SEVERITY_CRITICAL => 0,
            self::SEVERITY_WARNING  => 1,
            default                 => 2,
        };
    }
}
