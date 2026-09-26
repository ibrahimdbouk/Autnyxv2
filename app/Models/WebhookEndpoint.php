<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** W13 — a URL told about events, signed with its own secret. Notification only. */
class WebhookEndpoint extends Model
{
    public const EVENTS = [
        'investigation.opened'   => 'Investigation opened',
        'investigation.resolved' => 'Investigation resolved or closed',
        'outcome.measured'       => 'Recovery measured',
        'count.recorded'         => 'Cycle count recorded',
        'finding.opened'         => 'New finding (at or above the chosen severity)',
    ];

    /** Consecutive failed deliveries (after their retries) before an endpoint is switched off. */
    public const DISABLE_AFTER = 20;

    protected $fillable = ['tenant_id', 'name', 'url', 'secret', 'events', 'min_severity', 'active', 'last_finding_id',
        'failure_streak', 'last_success_at', 'last_failure_at', 'disabled_reason', 'created_by'];

    protected $casts = [
        'secret'          => 'encrypted',
        'events'          => 'array',
        'active'          => 'boolean',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    protected $hidden = ['secret'];

    public static function newSecret(): string
    {
        return 'whsec_' . Str::random(40);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function wants(string $event): bool
    {
        return $this->active && in_array($event, (array) $this->events, true);
    }
}
