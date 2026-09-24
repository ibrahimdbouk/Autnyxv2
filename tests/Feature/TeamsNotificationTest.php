<?php

namespace Tests\Feature;

use App\Models\TeamsConnection;
use App\Services\Teams\AdaptiveCardBuilder;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Microsoft Teams notification channel.
 *
 * Delivery is best-effort and DORMANT until a tenant configures a connection and
 * TEAMS_ENABLED is on — these guard both the off switch and the two delivery
 * paths (channel webhook + per-user Graph activity feed) with Http faked, so no
 * real Microsoft call is made. See claude/teams-notifications.md.
 */
class TeamsNotificationTest extends TestCase
{
    private function notifier(): TeamsNotifier
    {
        return app(TeamsNotifier::class);
    }

    public function test_dormant_when_disabled(): void
    {
        Http::fake();
        config(['services.teams.enabled' => false]);

        $tenant = $this->createTenant();
        tap(TeamsConnection::create([
            'tenant_id'           => $tenant->id,
            'channel_webhook_url' => 'https://acme.webhook.office.com/webhookb2/x',
            'post_to_channel'     => true,
            'notify_users'        => false,
            'is_active'           => true,
        ]), fn ($c) => $c->forceFill(['aad_tenant_id' => 'aad-1', 'aad_verified_at' => now()])->save());
        $user = $this->createUser($tenant);

        $this->notifier()->notify($tenant->id, collect([$user]), 'Hi', 'body', 'https://app.autnyx.com/x');

        Http::assertNothingSent();
    }

    public function test_dormant_when_no_active_connection(): void
    {
        Http::fake();
        config(['services.teams.enabled' => true]);

        $tenant = $this->createTenant();
        $user   = $this->createUser($tenant);

        $this->notifier()->notify($tenant->id, collect([$user]), 'Hi', 'body', 'https://app.autnyx.com/x');

        Http::assertNothingSent();
    }

    public function test_channel_post_via_webhook_and_records_success(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        config(['services.teams.enabled' => true]);

        $tenant = $this->createTenant();
        $conn = tap(TeamsConnection::create([
            'tenant_id'           => $tenant->id,
            'channel_webhook_url' => 'https://acme.webhook.office.com/webhookb2/teams',
            'post_to_channel'     => true,
            'notify_users'        => false,
            'is_active'           => true,
            'status'              => TeamsConnection::STATUS_NEVER,
        ]), fn ($c) => $c->forceFill(['aad_tenant_id' => 'aad-1', 'aad_verified_at' => now()])->save());
        $user = $this->createUser($tenant);

        $this->notifier()->notify($tenant->id, collect([$user]), 'Anomaly', 'Revenue at risk', 'https://app.autnyx.com/i/1');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://acme.webhook.office.com/webhookb2/teams'
                && $request['type'] === 'message'
                && ($request['attachments'][0]['contentType'] ?? null) === 'application/vnd.microsoft.card.adaptive';
        });

        $conn->refresh();
        $this->assertSame(TeamsConnection::STATUS_OK, $conn->status);
        $this->assertNotNull($conn->last_success_at);
    }

    public function test_per_user_activity_ping_via_graph(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*'       => Http::response([], 200),
        ]);
        config([
            'services.teams.enabled'       => true,
            'services.teams.client_id'     => 'cid',
            'services.teams.client_secret' => 'secret',
        ]);

        $tenant = $this->createTenant();
        tap(TeamsConnection::create([
            'tenant_id'       => $tenant->id,
            'post_to_channel' => false,
            'notify_users'    => true,
            'is_active'       => true,
        ]), fn ($c) => $c->forceFill(['aad_tenant_id' => 'aad-xyz', 'aad_verified_at' => now()])->save());
        $user = $this->createUser($tenant);
        $user->forceFill(['teams_aad_user_id' => 'user-guid-1'])->save();

        $this->notifier()->notify($tenant->id, collect([$user]), 'Anomaly', 'body', 'https://app.autnyx.com/i/9');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/users/user-guid-1/teamwork/sendActivityNotification'));
    }

    public function test_user_without_mapping_is_skipped(): void
    {
        Http::fake();
        config(['services.teams.enabled' => true]);

        $tenant = $this->createTenant();
        tap(TeamsConnection::create([
            'tenant_id'       => $tenant->id,
            'post_to_channel' => false,
            'notify_users'    => true,
            'is_active'       => true,
        ]), fn ($c) => $c->forceFill(['aad_tenant_id' => 'aad-xyz', 'aad_verified_at' => now()])->save());
        $user = $this->createUser($tenant); // no teams_aad_user_id

        $this->notifier()->notify($tenant->id, collect([$user]), 'Anomaly', 'body', null);

        Http::assertNothingSent();
    }

    public function test_adaptive_card_shape(): void
    {
        $card = app(AdaptiveCardBuilder::class)->build('Title', 'Body', 'https://app.autnyx.com/x', ['SKU' => 'ABC', 'Rule' => 'demand_erosion']);

        $this->assertSame('AdaptiveCard', $card['type']);
        $this->assertSame('Title', $card['body'][0]['text']);
        $this->assertSame('FactSet', $card['body'][2]['type']);
        $this->assertSame('Action.OpenUrl', $card['actions'][0]['type']);
        $this->assertSame('https://app.autnyx.com/x', $card['actions'][0]['url']);
    }
}
