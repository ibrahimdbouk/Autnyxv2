<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\RootCauseAnalysisService;
use Tests\TestCase;

/**
 * B8: deterministic root-cause inference over the retail causal graph.
 */
class RootCauseAnalysisTest extends TestCase
{
    private function svc(): RootCauseAnalysisService
    {
        return app(RootCauseAnalysisService::class);
    }

    public function test_full_supply_chain_is_verified(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S7', 'code' => 'ST07']);
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id, 'primary_sku' => 'SKU1']);

        // supplier under-fill → stockout → sales drop, all SKU1 / same store.
        $this->anom($tenant, $inv, 'supplier_fill_rate', 'SKU1', null,        'high', 3000);
        $this->anom($tenant, $inv, 'stockout_risk',      'SKU1', $store->id,  'high', 2000);
        $this->anom($tenant, $inv, 'sales_drop',         'SKU1', $store->id,  'medium', 1500);

        $r = $this->svc()->analyze($inv);

        $this->assertNotNull($r);
        $this->assertSame('supplier_fill_rate', $r['root_rule']);
        $this->assertSame('verified', $r['tier']);
        $this->assertCount(3, $r['chain']);
        $this->assertSame('supplier_fill_rate', $r['chain'][0]['rule']);
        $this->assertGreaterThanOrEqual(75, $r['confidence']);
    }

    public function test_single_link_is_likely(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id, 'primary_sku' => 'SKU2']);

        $this->anom($tenant, $inv, 'cost_spike',     'SKU2', null, 'high', 900);
        $this->anom($tenant, $inv, 'margin_erosion', 'SKU2', null, 'high', 800);

        $r = $this->svc()->analyze($inv);

        $this->assertSame('cost_spike', $r['root_rule']);
        $this->assertSame('likely', $r['tier']);
        $this->assertCount(2, $r['chain']);
    }

    public function test_unrelated_signals_are_correlated_only(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S1', 'code' => 'ST01']);
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id, 'primary_sku' => 'SKU3']);

        // No causal edge exists between these two.
        $this->anom($tenant, $inv, 'dead_stock',               'SKU3', $store->id, 'high', 500);
        $this->anom($tenant, $inv, 'duplicate_transaction_ids','SKU3', $store->id, 'low', 0);

        $r = $this->svc()->analyze($inv);

        $this->assertSame('correlated', $r['tier']);
        $this->assertSame(0, $r['links']);
        $this->assertSame('dead_stock', $r['root_rule']); // most significant of the two
    }

    public function test_single_anomaly_has_nothing_to_infer(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S1', 'code' => 'ST01']);
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id, 'primary_sku' => 'SKU4']);
        $this->anom($tenant, $inv, 'stockout_risk', 'SKU4', $store->id, 'high', 100);

        $this->assertNull($this->svc()->analyze($inv));
    }

    private function anom(Tenant $t, Investigation $inv, string $rule, ?string $sku, ?int $store, string $sev, float $impact): void
    {
        Anomaly::create([
            'tenant_id' => $t->id, 'investigation_id' => $inv->id, 'rule_type' => $rule,
            'severity' => $sev, 'sku' => $sku, 'store_id' => $store,
            'description' => $rule, 'context' => ['revenue_impact' => $impact], 'detected_at' => now(),
        ]);
    }
}
