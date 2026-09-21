<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\Integrations\SapS4HanaConnector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SAP S/4HANA OData hardening. Fixtures mirror the API Business Hub sandbox shape
 * (OData v2: d.results envelope, /Date(ms)/ timestamps, error envelope). All HTTP
 * is faked — a live keyed sandbox call is the connection's "Test" button.
 * See claude/api-integration-library.md.
 */
class SapConnectorTest extends TestCase
{
    private function sapConnection(): ApiConnection
    {
        return new ApiConnection([
            'provider'    => 'sap_s4hana',
            'base_url'    => 'https://sap.test/sap/opu/odata/sap',
            'auth_type'   => ApiConnection::AUTH_API_KEY,
            'auth_config' => ['header_name' => 'APIKey', 'header_value' => 'sandbox-key', 'sap_client' => '100'],
        ]);
    }

    public function test_registry_uses_the_sap_connector(): void
    {
        $this->assertInstanceOf(
            SapS4HanaConnector::class,
            app(ConnectorRegistry::class)->for($this->sapConnection())
        );
    }

    public function test_v2_envelope_dates_select_and_paging(): void
    {
        Http::fake([
            'sap.test/*' => Http::sequence()
                ->push(['d' => ['results' => [
                    ['Material' => 'M1', 'CreationDate' => '/Date(1609459200000)/', 'NetAmount' => '10.00'],
                    ['Material' => 'M2', 'CreationDate' => '/Date(1612137600000)/', 'NetAmount' => '20.00'],
                ]]], 200)
                ->push(['d' => ['results' => []]], 200),
        ]);

        $feed = new ApiFeed([
            'endpoint'  => '/API_SALES_ORDER_SRV/A_SalesOrderItem',
            'page_size' => 2,
            'field_map' => ['sku' => 'Material', 'date' => 'CreationDate', 'total_amount' => 'NetAmount'],
        ]);

        $connector = app(ConnectorRegistry::class)->for($this->sapConnection());
        $rows = iterator_to_array($connector->fetch($this->sapConnection(), $feed), false);

        // OData v2 dates decoded to ISO 8601.
        $this->assertSame([
            ['sku' => 'M1', 'date' => '2021-01-01T00:00:00+00:00', 'total_amount' => '10.00'],
            ['sku' => 'M2', 'date' => '2021-02-01T00:00:00+00:00', 'total_amount' => '20.00'],
        ], $rows);

        // SAP essentials injected on the request.
        Http::assertSent(function ($request) {
            $url = urldecode($request->url());

            return $request->hasHeader('APIKey', 'sandbox-key')
                && str_contains($url, '$format=json')
                && str_contains($url, '$select=Material,CreationDate,NetAmount')
                && str_contains($url, 'sap-client=100')
                && str_contains($url, '$top=2');
        });
    }

    public function test_odata_error_envelope_is_surfaced(): void
    {
        Http::fake([
            'sap.test/*' => Http::response(['error' => ['message' => ['value' => 'Property Foo not found']]], 400),
        ]);

        $feed = new ApiFeed(['endpoint' => '/API_SALES_ORDER_SRV/A_SalesOrderItem', 'field_map' => ['sku' => 'Material']]);
        $connector = app(ConnectorRegistry::class)->for($this->sapConnection());

        try {
            iterator_to_array($connector->fetch($this->sapConnection(), $feed), false);
            $this->fail('Expected a fetch failure.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Property Foo not found', $e->getMessage());
        }
    }

    public function test_v4_service_via_value_records_path(): void
    {
        Http::fake([
            'sap.test/*' => Http::response(['value' => [['Material' => 'V1']]], 200),
        ]);

        $feed = new ApiFeed([
            'endpoint'     => '/API_PRODUCT_SRV/A_Product',
            'records_path' => 'value', // v4 override
            'page_size'    => 100,
            'field_map'    => ['sku' => 'Material'],
        ]);

        $rows = iterator_to_array(app(ConnectorRegistry::class)->for($this->sapConnection())->fetch($this->sapConnection(), $feed), false);

        $this->assertSame([['sku' => 'V1']], $rows);
    }
}
