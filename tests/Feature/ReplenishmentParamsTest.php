<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\SkuReplenishment;
use App\Services\Anomaly\ReplenishmentService;
use App\Services\Integrations\ApiPollService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ingesting F&R replenishment parameters into sku_replenishment as tenant-supplied
 * (source=ingested), and the compute-precedence guard: the nightly compute defers
 * to ingested rows — never overwrites, never sweeps them.
 * See claude/api-integration-library.md.
 */
class ReplenishmentParamsTest extends TestCase
{
    public function test_params_feed_lands_as_ingested(): void
    {
        Http::fake(['relex.test/*' => Http::response(['items' => [
            ['Item' => 'SKU-1', 'ROP' => 50, 'SS' => 12, 'Lead' => 5],
        ]], 200)]);

        $tenant = $this->createTenant();
        $conn = ApiConnection::create([
            'tenant_id' => $tenant->id, 'name' => 'RELEX', 'provider' => 'relex',
            'base_url'  => 'https://relex.test', 'auth_type' => 'bearer',
            'auth_config' => ['token' => 't'], 'is_active' => true, 'status' => ApiConnection::STATUS_NEVER,
        ]);
        ApiFeed::create([
            'api_connection_id' => $conn->id, 'tenant_id' => $tenant->id,
            'data_type'    => ApiFeed::DATA_TYPE_REPLENISHMENT_PARAMS, 'endpoint' => '/params',
            'records_path' => 'items', 'page_strategy' => 'none', 'enabled' => true,
            'field_map'    => ['sku' => 'Item', 'reorder_point' => 'ROP', 'safety_stock' => 'SS', 'lead_time_days' => 'Lead'],
        ]);

        $this->assertSame(1, app(ApiPollService::class)->pollConnection($conn->fresh('feeds')));

        $row = SkuReplenishment::where('tenant_id', $tenant->id)->where('sku', 'SKU-1')->first();
        $this->assertNotNull($row);
        $this->assertSame(SkuReplenishment::SOURCE_INGESTED, $row->source);
        $this->assertEquals(50.0, (float) $row->reorder_point);
        $this->assertEquals(12.0, (float) $row->safety_stock);
        $this->assertEquals(5.0, (float) $row->lead_time_days);
    }

    public function test_compute_preserves_ingested_and_sweeps_stale_computed(): void
    {
        $tenant = $this->createTenant();

        $ingested = SkuReplenishment::create([
            'tenant_id' => $tenant->id, 'sku' => 'SKU-ING', 'store_id' => 0,
            'reorder_point' => 999, 'source' => SkuReplenishment::SOURCE_INGESTED,
            'computed_at' => now()->subDay(),
        ]);
        SkuReplenishment::create([
            'tenant_id' => $tenant->id, 'sku' => 'SKU-COMP', 'store_id' => 0,
            'reorder_point' => 5, 'source' => SkuReplenishment::SOURCE_COMPUTED,
            'computed_at' => now()->subDay(),
        ]);

        // No sku_profiles for this tenant → the compute writes nothing new, then sweeps.
        app(ReplenishmentService::class)->computeForTenant($tenant->id);

        // Ingested row untouched and NOT swept.
        $ingested->refresh();
        $this->assertSame(SkuReplenishment::SOURCE_INGESTED, $ingested->source);
        $this->assertEquals(999.0, (float) $ingested->reorder_point);

        // Stale computed row swept.
        $this->assertDatabaseMissing('sku_replenishment', ['tenant_id' => $tenant->id, 'sku' => 'SKU-COMP']);
    }
}
