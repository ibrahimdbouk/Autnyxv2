<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TeamsConnection — a tenant's Microsoft Teams notification target.
 *
 * The Autnyx Entra app is ONE multi-tenant app; its client id/secret live in
 * config('services.teams'). This row only stores the customer's identifiers:
 *  - aad_tenant_id       — the customer's Azure AD tenant GUID (for the token),
 *  - team_id/channel_id  — where channel Adaptive Cards are posted (Graph path),
 *  - channel_webhook_url — optional Workflows/Incoming-Webhook; preferred for
 *                          channel posts so it works without the protected
 *                          Graph ChannelMessage.Send permission,
 *  - teams_app_id        — the installed Autnyx Teams app id (activity feed),
 *  - post_to_channel / notify_users — which deliveries are on.
 *
 * See claude/teams-notifications.md.
 */
class TeamsConnection extends Model
{
    const STATUS_NEVER = 'never';
    const STATUS_OK    = 'ok';
    const STATUS_ERROR = 'error';

    protected $fillable = [
        'tenant_id',
        'name',
        'aad_tenant_id',
        'team_id',
        'channel_id',
        'teams_app_id',
        'channel_webhook_url',
        'post_to_channel',
        'notify_users',
        'is_active',
        'status',
        'last_success_at',
        'last_error',
        'last_error_at',
    ];

    protected $casts = [
        'post_to_channel'     => 'boolean',
        'notify_users'        => 'boolean',
        'is_active'           => 'boolean',
        'last_success_at'     => 'datetime',
        'last_error_at'       => 'datetime',
        // The webhook URL is a bearer-like secret — encrypt at rest.
        'channel_webhook_url' => 'encrypted',
    ];

    protected $hidden = ['channel_webhook_url'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_OK    => 'success',
            self::STATUS_ERROR => 'danger',
            default            => 'gray',
        };
    }
}
