<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P3.4 — an ingestion data contract for a tenant feed. Part of Platform\Governance.
 *
 * W9 (WP9.2): also the feed registry. Every feed that delivers a batch gets a
 * row; what it normally delivers (cadence, row band, header shape) is learned
 * from its history (`learned`). Explicit values (required_columns,
 * freshness_sla_hours, min_rows) are set by a person and always win.
 * $table is explicit (INC-012 guard).
 */
class DataContract extends Model
{
    protected $table = 'data_contracts';

    public const STATUS_OK      = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_LATE    = 'late';

    protected $fillable = [
        'tenant_id',
        'feed_key',
        'required_columns',
        'freshness_sla_hours',
        'min_rows',
        'active',
        'data_type',
        'source',
        'learned',
        'expected_every_hours',
        'rows_median',
        'rows_low',
        'rows_high',
        'batches_seen',
        'last_batch_at',
        'last_import_id',
        'last_rows',
        'header_signature',
        'header_columns',
        'owner_email',
        'status',
        'status_detail',
        'status_at',
    ];

    protected $casts = [
        'required_columns'     => 'array',
        'header_columns'       => 'array',
        'freshness_sla_hours'  => 'integer',
        'min_rows'             => 'integer',
        'active'               => 'boolean',
        'learned'              => 'boolean',
        'expected_every_hours' => 'integer',
        'rows_median'          => 'integer',
        'rows_low'             => 'integer',
        'rows_high'            => 'integer',
        'batches_seen'         => 'integer',
        'last_rows'            => 'integer',
        'last_batch_at'        => 'datetime',
        'status_at'            => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function violations(): HasMany
    {
        return $this->hasMany(ContractViolation::class);
    }

    public function isAutomated(): bool
    {
        return in_array($this->source, Import::AUTOMATED_SOURCES, true);
    }

    /** Hours after the last batch at which the next one counts as late (null = not tracked). */
    public function lateAfterHours(): ?int
    {
        if ($this->freshness_sla_hours) {
            return (int) $this->freshness_sla_hours;
        }
        if (! $this->isAutomated() || ! $this->expected_every_hours || $this->batches_seen < \App\Services\DataQuality\FeedMonitor::MIN_HISTORY) {
            return null;
        }

        // A learned cadence gets half a period of slack, at least 3 hours.
        return (int) ceil($this->expected_every_hours + max(3, $this->expected_every_hours / 2));
    }

    public function label(): string
    {
        $type = ucwords(str_replace('_', ' ', (string) $this->data_type));

        return match ($this->source) {
            Import::SOURCE_UPLOAD => "{$type} — uploads",
            Import::SOURCE_SFTP   => "{$type} — SFTP",
            Import::SOURCE_API    => "{$type} — API pull",
            Import::SOURCE_INGEST => "{$type} — ingest API",
            default               => $this->feed_key,
        };
    }
}
