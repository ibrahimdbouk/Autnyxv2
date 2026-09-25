<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\OutboundDispatch;
use App\Models\OutboundTarget;
use App\Models\PlanForecast;
use App\Services\Integrations\ApiPollService;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\ConnectorFactory;
use App\Platform\Integration\Connectors\BlueYonderConnector;
use App\Platform\Integration\Connectors\RelexConnector;
use App\Platform\Integration\Connectors\SlimstockConnector;
use App\Platform\Integration\OutboundDispatcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F&R (RELEX / Blue Yonder / Slimstock) integration: inbound forecast ingestion
 * into the planning baseline, and outbound action-intent write-back. HTTP faked.
 * See claude/api-integration-library.md.
 */
class FrIntegrationTest extends TestCase
{
    public function test_factory_routes_the_fr_kinds(): void
    {
        $f = app(ConnectorFactory::class);
        $this->assertInstanceOf(RelexConnector::class, $f->for(OutboundTarget::KIND_RELEX));
        $this->assertInstanceOf(BlueYonderConnector::class, $f->for(OutboundTarget::KIND_BLUE_YONDER));
        $this->assertInstanceOf(SlimstockConnector::class, $f->for(OutboundTarget::KIND_SLIMSTOCK));
    }

    public function test_outbound_writeback_to_relex_with_bearer_auth(): void
    {
        Http::fake(['relex.test/*' => Http::response(['ok' => true], 200)]);

        $tenant = $this->createTenant();
        $this->allowExecution($tenant);
        OutboundTarget::create([
            'tenant_id' => $tenant->id,
            'kind'      => OutboundTarget::KIND_RELEX,
            'name'      => 'RELEX',
            'endpoint'  => 'https://relex.test/intake',
            'config'    => ['auth' => 'bearer', 'token' => 'tok'],
            'active'    => true,
        ]);

        $intent = new ActionIntent(
            tenantId: $tenant->id,
            intentType: 'reorder',
            sku: 'SKU-1',
            quantity: 10.0,
            rationale: 'stockout risk',
            source: 'action:1',
        );

        $dispatch = app(OutboundDispatcher::class)->dispatch($intent);

        $this->assertSame(OutboundDispatch::STATUS_ACKNOWLEDGED, $dispatch->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'relex.test/intake')
            && $r->hasHeader('Authorization', 'Bearer tok')
            && ($r['intent_type'] ?? null) === 'reorder'
            && ($r['contract_version'] ?? null) === ActionIntent::CONTRACT_VERSION);
    }

    public function test_forecast_feed_lands_in_the_planning_baseline(): void
    {
        Http::fake(['relex.test/*' => Http::response(['items' => [
            ['ItemId' => 'SKU-1', 'Date' => '2026-02-01', 'Qty' => 12.5, 'Plant' => 'S1'],
            ['ItemId' => 'SKU-2', 'Date' => '2026-02-01', 'Qty' => 4.0,  'Plant' => 'S1'],
        ]], 200)]);

        $tenant = $this->createTenant();
        $conn = ApiConnection::create([
            'tenant_id' => $tenant->id, 'name' => 'RELEX', 'provider' => 'relex',
            'base_url'  => 'https://relex.test', 'auth_type' => 'bearer',
            'auth_config' => ['token' => 't'], 'is_active' => true, 'status' => ApiConnection::STATUS_NEVER,
        ]);
        ApiFeed::create([
            'api_connection_id' => $conn->id, 'tenant_id' => $tenant->id,
            'data_type'    => ApiFeed::DATA_TYPE_DEMAND_FORECAST, 'endpoint' => '/forecasts',
            'records_path' => 'items', 'page_strategy' => 'none', 'enabled' => true,
            'field_map'    => ['sku' => 'ItemId', 'target_date' => 'Date', 'forecast_qty' => 'Qty', 'store' => 'Plant'],
        ]);

        $ingested = app(ApiPollService::class)->pollConnection($conn->fresh('feeds'));

        $this->assertSame(1, $ingested);
        $this->assertSame(2, PlanForecast::where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('plan_forecasts', [
            'tenant_id'    => $tenant->id,
            'sku'          => 'SKU-1',
            'forecast_qty' => 12.5,
            'source'       => 'relex',
        ]);
    }
}
