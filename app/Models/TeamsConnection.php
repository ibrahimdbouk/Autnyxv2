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
        // aad_tenant_id is NOT fillable (WP2.2 / audit M7): it is set only by the
        // signed Microsoft admin sign-in (TeamsConsentController), never typed in.
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
        'aad_verified_at'     => 'datetime',
        // The webhook URL is a bearer-like secret — encrypt at rest.
        'channel_webhook_url' => 'encrypted',
    ];

    protected $hidden = ['channel_webhook_url'];

    /** Has a Microsoft 365 admin of aad_tenant_id proven it (signed sign-in)? */
    public function isMicrosoftTenantVerified(): bool
    {
        return ! empty($this->aad_tenant_id) && $this->aad_verified_at !== null;
    }

    /** Workflows / Incoming-Webhook hosts a channel webhook may point at. */
    public static function webhookHostAllowed(?string $url): bool
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url((string) $url, PHP_URL_SCHEME));
        if ($host === '' || $scheme !== 'https') {
            return false;
        }
        foreach ((array) config('services.teams.webhook_hosts', []) as $allowed) {
            $allowed = strtolower(ltrim((string) $allowed, '.'));
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

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
