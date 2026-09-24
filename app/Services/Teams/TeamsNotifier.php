<?php

namespace App\Services\Teams;

use App\Models\TeamsConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Teams notification channel — the counterpart to the email path in
 * NotificationDispatcher. Best-effort throughout: a Teams failure never breaks
 * the originating flow, and one recipient's failure never blocks the others.
 *
 * Delivery per the tenant's active TeamsConnection:
 *   - Channel post (if enabled): an Adaptive Card to the team channel. Prefers a
 *     Workflows/Incoming-Webhook URL when set (works without the protected Graph
 *     ChannelMessage.Send permission); falls back to Graph otherwise.
 *   - Per-user (if enabled): an activity-feed ping to each recipient that has a
 *     mapped teams_aad_user_id, via Graph sendActivityNotification.
 *
 * Dormant guard: does nothing unless config('services.teams.enabled') is true AND
 * the tenant has an active connection — so it ships dark until a tenant is set up.
 *
 * See claude/teams-notifications.md.
 */
class TeamsNotifier
{
    /** Must match an activityType declared in the Autnyx Teams app manifest. */
    public const ACTIVITY_TYPE = 'autnyxAlert';

    public function __construct(
        private GraphClient $graph,
        private AdaptiveCardBuilder $cards,
    ) {
    }

    /**
     * Notify a tenant's users about one event. $users should belong to $tenantId
     * (NotificationDispatcher groups by tenant before calling).
     *
     * @param  iterable<int,User>   $users
     * @param  array<string,string> $facts
     */
    public function notify(int $tenantId, iterable $users, string $title, ?string $body = null, ?string $url = null, array $facts = []): void
    {
        if (! config('services.teams.enabled')) {
            return;
        }

        $conn = TeamsConnection::where('tenant_id', $tenantId)->where('is_active', true)->first();
        if (! $conn) {
            return;
        }

        $card       = $this->cards->build($title, $body, $url, $facts);
        $anyOk       = false;
        $firstError = null;

        // 1) Channel post (one per event).
        if ($conn->post_to_channel) {
            try {
                $this->postToChannel($conn, $card);
                $anyOk = true;
            } catch (Throwable $e) {
                $firstError ??= $e->getMessage();
                Log::error('[TeamsNotifier] channel post: ' . $e->getMessage(), ['tenant_id' => $tenantId]);
            }
        }

        // 2) Per-user activity-feed pings.
        if ($conn->notify_users) {
            $preview = $title . ($body ? ' — ' . $body : '');
            foreach ($users as $user) {
                $aadUserId = $user->teams_aad_user_id ?? null;
                if (! $aadUserId) {
                    continue;
                }
                try {
                    $this->graph->sendUserActivityNotification(
                        $conn,
                        $aadUserId,
                        $url ?: config('app.url'),
                        self::ACTIVITY_TYPE,
                        [['name' => 'alert', 'value' => $title]],
                        mb_substr($preview, 0, 150),
                    );
                    $anyOk = true;
                } catch (Throwable $e) {
                    $firstError ??= $e->getMessage();
                    Log::error('[TeamsNotifier] activity ping: ' . $e->getMessage(), ['tenant_id' => $tenantId, 'user_id' => $user->id]);
                }
            }
        }

        $this->recordOutcome($conn, $anyOk, $firstError);
    }

    /** Prefer the Workflows/Incoming-Webhook when configured; else Graph. */
    private function postToChannel(TeamsConnection $conn, array $card): void
    {
        if ($conn->channel_webhook_url) {
            // WP2.2 (audit H12): only Microsoft webhook hosts, and SSRF-guarded.
            if (! TeamsConnection::webhookHostAllowed($conn->channel_webhook_url)) {
                throw new \RuntimeException('Webhook URL is not a Microsoft Teams / Workflows webhook host.');
            }
            $resp = app(\App\Support\Http\EgressGuard::class)
                ->apply(Http::timeout(20), $conn->channel_webhook_url)
                ->post($conn->channel_webhook_url, [
                'type'        => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl'  => null,
                    'content'     => $card,
                ]],
            ]);

            if (! $resp->successful()) {
                throw new \RuntimeException('Webhook post failed: HTTP ' . $resp->status() . ' ' . \Illuminate\Support\Str::limit($resp->body(), 300));
            }

            return;
        }

        $this->graph->postChannelMessage($conn, $card);
    }

    private function recordOutcome(TeamsConnection $conn, bool $anyOk, ?string $error): void
    {
        try {
            if ($anyOk && $error === null) {
                $conn->forceFill([
                    'status'          => TeamsConnection::STATUS_OK,
                    'last_success_at' => now(),
                    'last_error'      => null,
                    'last_error_at'   => null,
                ])->save();
            } elseif (! $anyOk && $error !== null) {
                $conn->forceFill([
                    'status'        => TeamsConnection::STATUS_ERROR,
                    'last_error'    => mb_substr($error, 0, 1000),
                    'last_error_at' => now(),
                ])->save();
            } else {
                // Partial success — keep the success stamp but record the error.
                $conn->forceFill([
                    'status'          => TeamsConnection::STATUS_OK,
                    'last_success_at' => now(),
                    'last_error'      => $error ? mb_substr($error, 0, 1000) : $conn->last_error,
                    'last_error_at'   => $error ? now() : $conn->last_error_at,
                ])->save();
            }
        } catch (Throwable $e) {
            Log::error('[TeamsNotifier] outcome record: ' . $e->getMessage());
        }
    }

    /**
     * Fire a one-off test notification for the Filament "Send test" action.
     * Throws on failure so the UI can surface the exact Graph/webhook error.
     */
    public function sendTest(TeamsConnection $conn, ?User $actor = null): void
    {
        $card = $this->cards->build(
            'Autnyx test notification',
            'If you can see this in Teams, your Autnyx connection is working.',
            config('app.url'),
            ['Connection' => $conn->name ?: ('Tenant ' . $conn->tenant_id)],
        );

        if ($conn->post_to_channel) {
            $this->postToChannel($conn, $card);
        }

        if ($conn->notify_users && $actor && $actor->teams_aad_user_id) {
            $this->graph->sendUserActivityNotification(
                $conn,
                $actor->teams_aad_user_id,
                config('app.url'),
                self::ACTIVITY_TYPE,
                [['name' => 'alert', 'value' => 'Autnyx test notification']],
                'Autnyx test notification',
            );
        }

        $this->recordOutcome($conn, true, null);
    }
}
