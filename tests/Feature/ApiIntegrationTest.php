<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\Import;
use App\Services\Integrations\ApiPollService;
use App\Services\Integrations\GenericRestConnector;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * API integration library — the connector framework + poll pipeline. All HTTP is
 * faked, so no live source system is needed. See claude/api-integration-library.md.
 */
class ApiIntegrationTest extends TestCase
{
    public function test_generic_connector_paginates_and_maps(): void
    {
        Http::fake([
            'api.test/*' => Http::sequence()
                ->push(['data' => [['id' => 1, 'amt' => 10]]], 200) // full page → continue
                ->push(['data' => []], 200),                        // empty → stop
        ]);

        $conn = new ApiConnection(['base_url' => 'https://api.test', 'auth_type' => 'none']);
        $feed = new ApiFeed([
            'endpoint'      => '/orders',
            'records_path'  => 'data',
            'page_strategy' => 'page',
            'page_size'     => 1,
            'field_map'     => ['sku' => 'id', 'total_amount' => 'amt'],
        ]);

        $connector = new GenericRestConnector(['page_param' => 'page', 'size_param' => 'per_page']);
        $rows = iterator_to_array($connector->fetch($conn, $feed), false);

        $this->assertSame([['sku' => 1, 'total_amount' => 10]], $rows);
    }

    public function test_oauth_client_credentials_and_bearer(): void
    {
        Http::fake([
            'login.test/token'  => Http::response(['access_token' => 'abc123', 'expires_in' => 3600], 200),
            'api.test/*'        => Http::response(['value' => [['id' => 7]]], 200),
        ]);

        $conn = new ApiConnection([
            'base_url'    => 'https://api.test',
            'auth_type'   => ApiConnection::AUTH_OAUTH2_CC,
            'auth_config' => ['token_url' => 'https://login.test/token', 'client_id' => 'c', 'client_secret' => 's'],
        ]);
        $feed = new ApiFeed(['endpoint' => '/items', 'records_path' => 'value', 'page_strategy' => 'none']);

        $rows = iterator_to_array((new GenericRestConnector())->fetch($conn, $feed), false);

        $this->assertSame([['id' => 7]], $rows);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.test/items')
            && $r->hasHeader('Authorization', 'Bearer abc123'));
    }

    public function test_link_header_pagination(): void
    {
        Http::fake([
            'api.test/orders*' => Http::sequence()
                ->push(['orders' => [['id' => 1]]], 200, ['Link' => '<https://api.test/orders?page_info=NEXT>; rel="next"'])
                ->push(['orders' => [['id' => 2]]], 200), // no Link → stop
        ]);

        $conn = new ApiConnection(['base_url' => 'https://api.test', 'auth_type' => 'none']);
        $feed = new ApiFeed(['endpoint' => '/orders', 'records_path' => 'orders', 'page_strategy' => 'link_header', 'page_size' => 1]);

        $rows = iterator_to_array((new GenericRestConnector(['size_param' => 'limit']))->fetch($conn, $feed), false);

        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
    }

    public function test_poll_ingests_through_the_import_pipeline(): void
    {
        Bus::fake();            // don't actually run detection
        Storage::fake('local');
        Http::fake([
            'erp.test/*' => Http::response(['value' => [
                ['sku' => 'SKU-1', 'date' => '2026-01-01', 'quantity' => 3, 'unit_price' => 5, 'store' => 'S1'],
                ['sku' => 'SKU-2', 'date' => '2026-01-02', 'quantity' => 1, 'unit_price' => 9, 'store' => 'S1'],
            ]], 200),
        ]);

        $tenant = $this->createTenant();
        $conn = ApiConnection::create([
            'tenant_id' => $tenant->id, 'name' => 'ERP', 'provider' => 'generic_rest',
            'base_url'  => 'https://erp.test', 'auth_type' => 'none', 'is_active' => true,
            'status'    => ApiConnection::STATUS_NEVER,
        ]);
        ApiFeed::create([
            'api_connection_id' => $conn->id, 'tenant_id' => $tenant->id,
            'data_type'         => Import::TYPE_SALES, 'endpoint' => '/sales',
            'records_path'      => 'value', 'page_strategy' => 'none', 'enabled' => true,
            'field_map'         => ['sku' => 'sku', 'date' => 'date', 'quantity' => 'quantity', 'unit_price' => 'unit_price', 'store' => 'store'],
        ]);

        $ingested = app(ApiPollService::class)->pollConnection($conn->fresh('feeds'));

        $this->assertSame(1, $ingested);

        $import = Import::where('tenant_id', $tenant->id)->where('data_type', Import::TYPE_SALES)->latest('id')->first();
        $this->assertNotNull($import);
        $this->assertSame(2, (int) $import->total_rows);

        $this->assertSame(ApiConnection::STATUS_OK, $conn->fresh()->status);
    }
}
