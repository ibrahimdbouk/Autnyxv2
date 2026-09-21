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
            ->assertStatus(201)
            ->assertJsonPath('data_type', Import::TYPE_SALES)
            ->assertJsonPath('total_rows', 2);

        $this->assertTrue(
            Import::where('tenant_id', $tenant->id)->where('data_type', Import::TYPE_SALES)->exists()
        );
    }

    public function test_ingest_requires_write_scope(): void
    {
        $tenant = $this->createTenant();
        [, $token] = $this->key($tenant->id, [ApiKey::SCOPE_READ_INVESTIGATIONS]);

        $this->postJson('/api/v1/ingest', ['data_type' => Import::TYPE_SALES, 'rows' => [['sku' => 'X']]], $this->auth($token))
            ->assertStatus(403);
    }
}
