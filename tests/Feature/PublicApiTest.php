<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Import;
use App\Models\Investigation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Public API v1 — key auth, scopes, tenant isolation, ingest. See claude/public-api.md.
 */
class PublicApiTest extends TestCase
{
    /** @return array{0:ApiKey,1:string} */
    private function key(int $tenantId, array $scopes): array
    {
        return ApiKey::generate($tenantId, 'test', $scopes);
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_openapi_is_public(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('info.title', 'Autnyx Public API');
    }

    public function test_missing_key_is_unauthorized(): void
    {
        $this->getJson('/api/v1/investigations')->assertStatus(401);
    }

    public function test_valid_key_reads_own_tenant_only(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();

        $mine = Investigation::factory()->create(['tenant_id' => $a->id, 'title' => 'Mine']);
        Investigation::factory()->create(['tenant_id' => $b->id, 'title' => 'Theirs']);

        [, $token] = $this->key($a->id, [ApiKey::SCOPE_READ_INVESTIGATIONS]);

        $this->getJson('/api/v1/investigations', $this->auth($token))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_scope_is_enforced(): void
    {
        $tenant = $this->createTenant();
        [, $token] = $this->key($tenant->id, [ApiKey::SCOPE_READ_INVESTIGATIONS]); // no anomalies scope

        $this->getJson('/api/v1/anomalies', $this->auth($token))->assertStatus(403);
    }

    public function test_revoked_key_is_rejected(): void
    {
        $tenant = $this->createTenant();
        [$key, $token] = $this->key($tenant->id, [ApiKey::SCOPE_READ_INVESTIGATIONS]);
        $key->forceFill(['revoked_at' => now()])->save();

        $this->getJson('/api/v1/investigations', $this->auth($token))->assertStatus(401);
    }

    public function test_expired_key_is_rejected(): void
    {
        $tenant = $this->createTenant();
        [, $token] = ApiKey::generate($tenant->id, 'old', [ApiKey::SCOPE_READ_INVESTIGATIONS], now()->subDay());

        $this->getJson('/api/v1/investigations', $this->auth($token))->assertStatus(401);
    }

    public function test_ingest_creates_an_import(): void
    {
        Bus::fake();
        Storage::fake('local');

        $tenant = $this->createTenant();
        [, $token] = $this->key($tenant->id, [ApiKey::SCOPE_WRITE_INGEST]);

        $payload = [
            'data_type' => Import::TYPE_SALES,
            'rows'      => [
                ['sku' => 'SKU-1', 'date' => '2026-01-01', 'quantity' => 2, 'unit_price' => 5],
                ['sku' => 'SKU-2', 'date' => '2026-01-02', 'quantity' => 1, 'unit_price' => 9],
            ],
        ];

        $this->postJson('/api/v1/ingest', $payload, $this->auth($token))
            ->assertStatus(202) // WP2.3: queued, not processed inside the request
            ->assertJsonPath('data_type', Import::TYPE_SALES)
            ->assertJsonPath('total_rows', 2);

        $this->assertTrue(
            Import::where('tenant_id', $tenant->id)->where('data_type', Import::TYPE_SALES)->exists()
        );
        Bus::assertDispatched(\App\Jobs\ProcessIngestedImportJob::class);
    }

    // ── WP2.3 ────────────────────────────────────────────────────────────────

    public function test_ingest_is_idempotent_with_an_idempotency_key_and_pollable(): void
    {
        Bus::fake();
        Storage::fake('local');
        $tenant = $this->createTenant();
        [, $token] = $this->key($tenant->id, [ApiKey::SCOPE_WRITE_INGEST]);
        $payload = ['data_type' => Import::TYPE_SALES, 'rows' => [['sku' => 'S', 'date' => '2026-01-01', 'quantity' => 1]]];
        $headers = $this->auth($token) + ['Idempotency-Key' => 'order-batch-42'];

        $first = $this->postJson('/api/v1/ingest', $payload, $headers)->assertStatus(202)->json('import_id');
        $this->postJson('/api/v1/ingest', $payload, $headers)->assertStatus(200)
            ->assertJsonPath('import_id', $first)->assertJsonPath('idempotent_replay', true);
        $this->assertSame(1, Import::where('tenant_id', $tenant->id)->count(), 'a retried request does not duplicate the data');

        $this->getJson("/api/v1/imports/{$first}", $this->auth($token))->assertOk()->assertJsonPath('import_id', $first);

        // Another tenant cannot poll it.
        $other = $this->createTenant();
        [, $otherToken] = $this->key($other->id, [ApiKey::SCOPE_WRITE_INGEST]);
        $this->getJson("/api/v1/imports/{$first}", $this->auth($otherToken))->assertNotFound();
    }

    public function test_users_cannot_be_ingested_over_the_api(): void
    {
        $tenant = $this->createTenant();
        [, $token] = $this->key($tenant->id, [ApiKey::SCOPE_WRITE_INGEST]);

        $this->postJson('/api/v1/ingest', ['data_type' => Import::TYPE_USERS, 'rows' => [['email' => 'x@y.z', 'name' => 'X', 'role' => 'admin']]], $this->auth($token))
            ->assertStatus(422);
    }

    public function test_suspended_tenant_keys_stop_working(): void
    {
        $tenant = $this->createTenant();
        [, $token] = $this->key($tenant->id, [ApiKey::SCOPE_READ_INVESTIGATIONS]);
        $this->getJson('/api/v1/investigations', $this->auth($token))->assertOk();

        $tenant->update(['status' => \App\Models\Tenant::STATUS_SUSPENDED]);
        $this->getJson('/api/v1/investigations', $this->auth($token))->assertForbidden();
    }

    public function test_failed_authentication_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/investigations', ['Authorization' => 'Bearer atx_wrong_' . $i])->assertStatus(401);
        }
        $this->getJson('/api/v1/investigations', ['Authorization' => 'Bearer atx_wrong_x'])->assertStatus(429);
    }

    public function test_ingest_requires_write_scope(): void
    {
        $tenant = $this->createTenant();
        [, $token] = $this->key($tenant->id, [ApiKey::SCOPE_READ_INVESTIGATIONS]);

        $this->postJson('/api/v1/ingest', ['data_type' => Import::TYPE_SALES, 'rows' => [['sku' => 'X']]], $this->auth($token))
            ->assertStatus(403);
    }
}
