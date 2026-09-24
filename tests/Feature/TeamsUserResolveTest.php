<?php

namespace Tests\Feature;

use App\Models\TeamsConnection;
use App\Services\Teams\GraphClient;
use App\Services\Teams\TeamsUserResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Auto-resolving users' teams_aad_user_id from email via Graph, so admins don't
 * map users to Teams by hand. Graph is faked. See claude/teams-notifications.md.
 */
class TeamsUserResolveTest extends TestCase
{
    private function enable(): void
    {
        config([
            'services.teams.enabled'       => true,
            'services.teams.client_id'     => 'cid',
            'services.teams.client_secret' => 'secret',
        ]);
    }

    private function graphOk(string $id = 'AAD-1'): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*'       => Http::response(['value' => [['id' => $id]]], 200),
        ]);
    }

    public function test_resolve_user_id_by_email_returns_id(): void
    {
        $this->enable();
        $this->graphOk('AAD-XYZ');

        $tenant = $this->createTenant();
        $conn = TeamsConnection::create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $conn->forceFill(['aad_tenant_id' => 'aad-1', 'aad_verified_at' => now()])->save();

        $id = app(GraphClient::class)->resolveUserIdByEmail($conn, "o'brien@example.com");

        $this->assertSame('AAD-XYZ', $id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.microsoft.com/v1.0/users')
            && str_contains(urldecode($r->url()), "mail eq 'o''brien@example.com'"));
    }

    public function test_resync_fills_missing_mappings(): void
    {
        $this->enable();
        $this->graphOk('AAD-1');

        $tenant = $this->createTenant();
        tap(TeamsConnection::create(['tenant_id' => $tenant->id, 'is_active' => true]), fn ($c) => $c->forceFill(['aad_tenant_id' => 'aad-1', 'aad_verified_at' => now()])->save());

        $mapped = $this->createUser($tenant); // has email, no id → should map

        $r = app(TeamsUserResolver::class)->resyncTenant($tenant->id);

        $this->assertSame('AAD-1', $mapped->fresh()->teams_aad_user_id);
        $this->assertGreaterThanOrEqual(1, $r['updated']);
    }

    public function test_resync_dormant_without_connection(): void
    {
        $this->enable();
        Http::fake();

        $tenant = $this->createTenant();
        $this->createUser($tenant);

        $r = app(TeamsUserResolver::class)->resyncTenant($tenant->id);

        $this->assertSame(['updated' => 0, 'skipped' => 0, 'failed' => 0], $r);
        Http::assertNothingSent();
    }

    public function test_resync_dormant_when_disabled(): void
    {
        config(['services.teams.enabled' => false]);
        Http::fake();

        $tenant = $this->createTenant();
        tap(TeamsConnection::create(['tenant_id' => $tenant->id, 'is_active' => true]), fn ($c) => $c->forceFill(['aad_tenant_id' => 'aad-1', 'aad_verified_at' => now()])->save());
        $this->createUser($tenant);

        app(TeamsUserResolver::class)->resyncTenant($tenant->id);

        Http::assertNothingSent();
    }
}
