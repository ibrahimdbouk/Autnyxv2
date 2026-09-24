<?php

namespace App\Services\Teams;

use App\Models\TeamsConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin Microsoft Graph client for the Teams notification channel (app-only,
 * client-credentials flow against ONE multi-tenant Autnyx Entra app — the
 * client id/secret live in config('services.teams'), not per connection).
 *
 * Two capabilities, both app-only:
 *  - postChannelMessage()  — an Adaptive Card into a team channel.
 *                            (Graph ChannelMessage.Send is a *protected* API that
 *                            needs Microsoft approval; TeamsConnection therefore
 *                            also supports a Workflows/Incoming-Webhook URL that
 *                            TeamsNotifier prefers when present — see that class.)
 *  - sendUserActivityNotification() — a per-user activity-feed ping with a deep
 *                            link (TeamsActivity.Send — NOT protected — plus the
 *                            Autnyx Teams app installed for the user).
 *
 * Tokens are cached per customer AAD tenant until shortly before expiry.
 * See claude/teams-notifications.md.
 */
class GraphClient
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Access token for a customer AAD tenant, cached until ~1 min before expiry. */
    public function token(string $aadTenantId): string
    {
        $clientId     = (string) config('services.teams.client_id');
        $clientSecret = (string) config('services.teams.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Teams client credentials are not configured.');
        }

        $cacheKey = 'teams_graph_token_' . $aadTenantId;

        return Cache::get($cacheKey) ?? $this->fetchToken($aadTenantId, $clientId, $clientSecret, $cacheKey);
    }

    private function fetchToken(string $aadTenantId, string $clientId, string $clientSecret, string $cacheKey): string
    {
        $resp = Http::asForm()
            ->timeout(15)
            ->post('https://login.microsoftonline.com/' . rawurlencode($aadTenantId) . '/oauth2/v2.0/token', [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default',
            ]);

        if (! $resp->successful() || ! $resp->json('access_token')) {
            throw new RuntimeException('Teams token request failed: HTTP ' . $resp->status() . ' ' . $resp->body());
        }

        $token   = (string) $resp->json('access_token');
        $expires = (int) ($resp->json('expires_in') ?: 3600);

        Cache::put($cacheKey, $token, max(60, $expires - 60));

        return $token;
    }

    /**
     * Post an Adaptive Card to the connection's team channel via Graph.
     * Prefer TeamsNotifier's webhook path when a webhook URL is set; this is the
     * Graph fallback and needs the protected ChannelMessage.Send permission.
     */
    public function postChannelMessage(TeamsConnection $conn, array $adaptiveCard): void
    {
        if (! $conn->team_id || ! $conn->channel_id) {
            throw new RuntimeException('Teams connection is missing team_id/channel_id for a channel post.');
        }
        $this->assertVerified($conn);

        $resp = Http::withToken($this->token($conn->aad_tenant_id))
            ->timeout(20)
            ->post(self::GRAPH . '/teams/' . rawurlencode($conn->team_id) . '/channels/' . rawurlencode($conn->channel_id) . '/messages', [
                'body' => [
                    'contentType' => 'html',
                    'content'     => '<attachment id="card"></attachment>',
                ],
                'attachments' => [[
                    'id'          => 'card',
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content'     => json_encode($adaptiveCard),
                ]],
            ]);

        if (! $resp->successful()) {
            throw new RuntimeException('Teams channel post failed: HTTP ' . $resp->status() . ' ' . $resp->body());
        }
    }

    /**
     * Send a per-user Teams activity-feed notification with a deep link back
     * into Autnyx. Requires the Autnyx Teams app installed for the user and the
     * TeamsActivity.Send application permission (admin-consented, not protected).
     *
     * @param  array<int,array{name:string,value:string}>  $templateParameters
     */
    public function sendUserActivityNotification(
        TeamsConnection $conn,
        string $aadUserId,
        string $deepLink,
        string $activityType,
        array $templateParameters,
        string $previewText,
    ): void {
        $this->assertVerified($conn);

        $resp = Http::withToken($this->token($conn->aad_tenant_id))
            ->timeout(20)
            ->post(self::GRAPH . '/users/' . rawurlencode($aadUserId) . '/teamwork/sendActivityNotification', [
                'topic' => [
                    'source' => 'text',
                    'value'  => 'Autnyx',
                    'webUrl' => $deepLink,
                ],
                'activityType'    => $activityType,
                'previewText'     => ['content' => $previewText],
                'templateParameters' => $templateParameters,
            ]);

        if (! $resp->successful()) {
            throw new RuntimeException('Teams activity notification failed: HTTP ' . $resp->status() . ' ' . $resp->body());
        }
    }

    /**
     * Resolve an Azure AD user object id from an email address, so users don't
     * have to be mapped to Teams by hand. Matches either mail or userPrincipalName
     * (they often differ). Requires the User.Read.All application permission
     * (admin-consented). Returns null when no user matches.
     */
    public function resolveUserIdByEmail(TeamsConnection $conn, string $email): ?string
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $this->assertVerified($conn);

        // Escape single quotes for the OData string literal (' → '').
        $literal = str_replace("'", "''", $email);
        $filter  = "mail eq '{$literal}' or userPrincipalName eq '{$literal}'";

        $resp = Http::withToken($this->token($conn->aad_tenant_id))
            ->timeout(20)
            ->get(self::GRAPH . '/users', [
                '$filter' => $filter,
                '$select' => 'id',
                '$top'    => 1,
            ]);

        if (! $resp->successful()) {
            throw new RuntimeException('Teams user lookup failed: HTTP ' . $resp->status() . ' ' . $resp->body());
        }

        $id = $resp->json('value.0.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * WP2.2 (audit H12 / M7): Autnyx's multi-tenant Entra app can obtain tokens
     * for ANY Microsoft tenant that consented to it. Only act for a Microsoft
     * tenant this connection has PROVEN it administers (signed admin sign-in).
     */
    private function assertVerified(TeamsConnection $conn): void
    {
        if (! $conn->isMicrosoftTenantVerified()) {
            throw new RuntimeException('This Teams connection is not linked to a verified Microsoft 365 tenant yet — use "Connect Microsoft 365".');
        }
    }
}
