<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** WP5.2 — one tenant's nightly chain for one local date. */
class TenantNightlyRun extends Model
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = ['tenant_id', 'local_date', 'status', 'steps', 'started_at', 'finished_at', 'agents_dispatched_at'];

    protected $casts = [
        'local_date'           => 'date',
        'steps'                => 'array',
        'started_at'           => 'datetime',
        'finished_at'          => 'datetime',
        'agents_dispatched_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }
}
