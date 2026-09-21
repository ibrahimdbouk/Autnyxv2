<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\Integrations\DynamicsConnector;
use App\Services\Integrations\DynamicsFnoConnector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dynamics 365 hardening — Business Central and Finance & Operations. Both are
 * OData v4 behind Entra OAuth2; fixtures reproduce their shapes. HTTP is faked.
 * See claude/api-integration-library.md.
 */
class DynamicsConnectorTest extends TestCase
{
    private function bcConnection(): ApiConnection
    {
        return new ApiConnection([
            'provider'    => 'dynamics_bc',
            'base_url'    => 'https://api.businesscentral.dynamics.com/v2.0/tenant/prod/api/v2.0',
            'auth_type'   => ApiConnection::AUTH_OAUTH2_CC,
            'auth_config' => ['token_url' => 'https://login.test/token', 'client_id' => 'c', 'client_secret' => 's'],
        ]);
    }

    private function fnoConnection(bool $crossCompany = true): ApiConnection
    {
        return new ApiConnection([
            'provider'    => 'dynamics_fno',
            'base_url'    => 'https://contoso.operations.dynamics.com/data',
            'auth_type'   => ApiConnection::AUTH_OAUTH2_CC,
            'auth_config' => ['token_url' => 'https://login.test/token', 'client_id' => 'c', 'client_secret' => 's', 'cross_company' => $crossCompany],
        ]);
    }

    public function test_registry_routes_bc_and_fno(): void
    {
        $registry = app(ConnectorRegistry::class);
        $this->assertInstanceOf(DynamicsConnector::class, $registry->for($this->bcConnection()));
        $this->assertInstanceOf(DynamicsFnoConnector::class, $registry->for($this->fnoConnection()));
    }

    public function test_bc_derives_azure_ad_scope_and_follows_nextlink(): void
    {
        Http::fake([
            'login.test/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'api.businesscentral.dynamics.com/*' => Http::sequence()
                ->push(['value' => [['no' => 'S1']], '@odata.nextLink' => 'https://api.businesscentral.dynamics.com/next?page=2'], 200)
                ->push(['value' => [['no' => 'S2']]], 200),
        ]);

        $feed = new ApiFeed(['endpoint' => '/companies(1)/salesInvoices', 'field_map' => ['sku' => 'no']]);
        $rows = iterator_to_array(app(ConnectorRegistry::class)->for($this->bcConnection())->fetch($this->bcConnection(), $feed), false);

        $this->assertSame([['sku' => 'S1'], ['sku' => 'S2']], $rows);

        // The Azure AD scope is derived from the resource host.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'login.test')
            && ($r['scope'] ?? null) === 'https://api.businesscentral.dynamics.com/.default');
    }

    public function test_fno_adds_cross_company(): void
    {
        Http::fake([
            'login.test/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'contoso.operations.dynamics.com/*' => Http::response(['value' => [['id' => 'SO1']]], 200),
        ]);

        $feed = new ApiFeed(['endpoint' => '/SalesOrderHeadersV2', 'field_map' => ['order_id' => 'id']]);
        $rows = iterator_to_array(app(ConnectorRegistry::class)->for($this->fnoConnection())->fetch($this->fnoConnection(), $feed), false);

        $this->assertSame([['order_id' => 'SO1']], $rows);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'operations.dynamics.com')
            && str_contains(urldecode($r->url()), 'cross-company=true'));
    }

    public function test_fno_omits_cross_company_when_disabled(): void
    {
        Http::fake([
            'login.test/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'contoso.operations.dynamics.com/*' => Http::response(['value' => []], 200),
        ]);

        $feed = new ApiFeed(['endpoint' => '/SalesOrderHeadersV2', 'field_map' => ['order_id' => 'id']]);
        iterator_to_array(app(ConnectorRegistry::class)->for($this->fnoConnection(false))->fetch($this->fnoConnection(false), $feed), false);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'operations.dynamics.com')
            && ! str_contains(urldecode($r->url()), 'cross-company'));
    }

    public function test_v4_error_envelope_is_surfaced(): void
    {
        Http::fake([
            'login.test/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            'api.businesscentral.dynamics.com/*' => Http::response(['error' => ['code' => 'BadRequest', 'message' => 'Could not find a property named Foo']], 400),
        ]);

        $feed = new ApiFeed(['endpoint' => '/companies(1)/items', 'field_map' => ['sku' => 'no']]);
        $connector = app(ConnectorRegistry::class)->for($this->bcConnection());

        try {
            iterator_to_array($connector->fetch($this->bcConnection(), $feed), false);
            $this->fail('Expected a fetch failure.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Could not find a property named Foo', $e->getMessage());
            $this->assertStringContainsString('BadRequest', $e->getMessage());
        }
    }
}
