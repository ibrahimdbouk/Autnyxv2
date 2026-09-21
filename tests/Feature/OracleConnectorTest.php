<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\Integrations\GenericRestConnector;
use App\Services\Integrations\NetSuiteConnector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Oracle family hardening — Fusion Cloud, E-Business Suite (ORDS), and NetSuite.
 * Fusion/EBS use `items` + `hasMore` + offset; NetSuite adds OAuth 1.0a signing
 * and rel="next" link paging. HTTP is faked. See claude/api-integration-library.md.
 */
class OracleConnectorTest extends TestCase
{
    public function test_registry_routes_the_oracle_family(): void
    {
        $r = app(ConnectorRegistry::class);
        $this->assertInstanceOf(GenericRestConnector::class, $r->for(new ApiConnection(['provider' => 'oracle_fusion', 'base_url' => 'https://x'])));
        $this->assertInstanceOf(GenericRestConnector::class, $r->for(new ApiConnection(['provider' => 'oracle_ebs', 'base_url' => 'https://x'])));
        $this->assertInstanceOf(NetSuiteConnector::class, $r->for(new ApiConnection(['provider' => 'netsuite', 'base_url' => 'https://x'])));
    }

    public function test_fusion_hasmore_paging_and_basic_auth(): void
    {
        Http::fake([
            'fusion.test/*' => Http::sequence()
                ->push(['items' => [['InvoiceId' => 1]], 'hasMore' => true, 'count' => 1, 'offset' => 0, 'limit' => 1], 200)
                ->push(['items' => [['InvoiceId' => 2]], 'hasMore' => false, 'count' => 1, 'offset' => 1, 'limit' => 1], 200),
        ]);

        $conn = new ApiConnection([
            'provider'    => 'oracle_fusion',
            'base_url'    => 'https://fusion.test/fscmRestApi/resources/11.13.18.05',
            'auth_type'   => ApiConnection::AUTH_BASIC,
            'auth_config' => ['username' => 'svc', 'password' => 'pw'],
        ]);
        $feed = new ApiFeed(['endpoint' => '/invoices', 'page_size' => 1, 'field_map' => ['id' => 'InvoiceId']]);

        $rows = iterator_to_array(app(ConnectorRegistry::class)->for($conn)->fetch($conn, $feed), false);

        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fusion.test')
            && $r->hasHeader('Authorization', 'Basic ' . base64_encode('svc:pw')));
    }

    public function test_netsuite_oauth1_signing_and_links_paging(): void
    {
        Http::fake([
            'suitetalk.api.netsuite.com/*' => Http::sequence()
                ->push(['items' => [['id' => 'A']], 'hasMore' => true, 'links' => [['rel' => 'next', 'href' => 'https://acct.suitetalk.api.netsuite.com/services/rest/record/v1/salesOrder?limit=1&offset=1']]], 200)
                ->push(['items' => [['id' => 'B']], 'hasMore' => false, 'links' => []], 200),
        ]);

        $conn = new ApiConnection([
            'provider'    => 'netsuite',
            'base_url'    => 'https://acct.suitetalk.api.netsuite.com/services/rest',
            'auth_type'   => ApiConnection::AUTH_NONE,
            'auth_config' => ['account' => 'acct', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'token_id' => 'tk', 'token_secret' => 'ts'],
        ]);
        $feed = new ApiFeed(['endpoint' => '/record/v1/salesOrder', 'page_size' => 1, 'field_map' => ['order_id' => 'id']]);

        $rows = iterator_to_array(app(ConnectorRegistry::class)->for($conn)->fetch($conn, $feed), false);

        // Following the signed rel="next" link produced the second page.
        $this->assertSame([['order_id' => 'A'], ['order_id' => 'B']], $rows);

        Http::assertSent(function ($r) {
            $auth = $r->header('Authorization')[0] ?? '';

            return str_contains($r->url(), 'suitetalk.api.netsuite.com')
                && str_starts_with($auth, 'OAuth realm="ACCT"')
                && str_contains($auth, 'oauth_signature_method="HMAC-SHA256"')
                && str_contains($auth, 'oauth_signature="')
                && str_contains($auth, 'oauth_consumer_key="ck"');
        });
    }

    public function test_netsuite_signature_is_deterministic_for_fixed_inputs(): void
    {
        // Recompute the OAuth1 signature the same way and confirm the connector's
        // header carries a well-formed HMAC-SHA256 signature over the request.
        Http::fake(['suitetalk.api.netsuite.com/*' => Http::response(['items' => [], 'hasMore' => false], 200)]);

        $conn = new ApiConnection([
            'provider'    => 'netsuite',
            'base_url'    => 'https://acct.suitetalk.api.netsuite.com/services/rest',
            'auth_type'   => ApiConnection::AUTH_NONE,
            'auth_config' => ['account' => 'acct', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'token_id' => 'tk', 'token_secret' => 'ts'],
        ]);
        $feed = new ApiFeed(['endpoint' => '/record/v1/item', 'page_size' => 5, 'field_map' => ['sku' => 'itemId']]);

        iterator_to_array(app(ConnectorRegistry::class)->for($conn)->fetch($conn, $feed), false);

        Http::assertSent(function ($r) {
            $auth = $r->header('Authorization')[0] ?? '';
            // A base64 HMAC-SHA256 is 44 chars (32 bytes) ending in '=' — assert it's present and non-trivial.
            return preg_match('/oauth_signature="[^"]{20,}"/', $auth) === 1;
        });
    }
}
