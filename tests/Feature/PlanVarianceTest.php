<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\PlanForecast;
use App\Models\PurchaseOrder;
use App\Models\SalesDaily;
use App\Models\Store;
use App\Services\Anomaly\AnomalyDetectionService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Actual-vs-PLAN detection: the `plan_variance` rule measures reality against the
 * tenant's OWN ingested forecast (plan_forecasts), not Autnyx's best-fit baseline.
 * A SKU tracking its plan stays silent; one that departs beyond the tolerance band
 * flags, chain-wide or per store. See claude/api-integration-library.md.
 */
class PlanVarianceTest extends TestCase
{
    /** @param array<int> $daysAgo */
    private function seedPlanAndSales(
        int $tenantId, string $sku, ?int $storeId, float $planPerDay, float $soldPerDay, array $daysAgo
    ): void {
        foreach ($daysAgo as $d) {
            $date = Carbon::today()->subDays($d)->toDateString();
            PlanForecast::create([
                'tenant_id' => $tenantId, 'sku' => $sku, 'store_id' => $storeId,
                'target_date' => $date, 'forecast_qty' => $planPerDay, 'source' => 'relex',
            ]);
            SalesDaily::create([
                'tenant_id' => $tenantId, 'sku' => $sku, 'store_id' => $storeId,
                'date' => $date, 'units_sold' => $soldPerDay, 'revenue' => 0, 'transaction_count' => 1,
            ]);
        }
    }

    public function test_below_plan_flags_and_on_plan_stays_silent(): void
    {
        $tenant = $this->createTenant();
        $window = [1, 2, 3, 4, 5];

        // PV-LOW: planned 100/day, sold 20/day → 100 vs 500, 80% below → flags.
        $this->seedPlanAndSales($tenant->id, 'PV-LOW', null, 100, 20, $window);
        // PV-OK: planned 100/day, sold 100/day → on plan → silent.
        $this->seedPlanAndSales($tenant->id, 'PV-OK', null, 100, 100, $window);

        app(AnomalyDetectionService::class)->runForTenant($tenant->id);

        $flagged = Anomaly::where('tenant_id', $tenant->id)
            ->where('rule_type', 'plan_variance')->where('sku', 'PV-LOW')->first();
        $this->assertNotNull($flagged, 'selling 80% below the ingested plan should flag');
        $this->assertNull($flagged->store_id, 'a chain-level plan flags chain-wide');
        $this->assertSame('below', $flagged->context['direction']);
        $this->assertSame('relex', $flagged->context['plan_source']);

        $this->assertDatabaseMissing('anomalies', [
            'tenant_id' => $tenant->id, 'rule_type' => 'plan_variance', 'sku' => 'PV-OK',
        ]);
    }

    public function test_store_level_plan_variance_flags_that_store_when_above(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'Alpha', 'code' => 'STA']);

        // Planned 50/day at that store, sold 120/day → 600 vs 250, well above → flags.
        $this->seedPlanAndSales($tenant->id, 'PV-HI', $store->id, 50, 120, [1, 2, 3, 4, 5]);

        app(AnomalyDetectionService::class)->runForTenant($tenant->id);

        $flagged = Anomaly::where('tenant_id', $tenant->id)
            ->where('rule_type', 'plan_variance')->where('sku', 'PV-HI')->first();
        $this->assertNotNull($flagged, 'selling far above the store plan should flag');
        $this->assertSame($store->id, $flagged->store_id);
        $this->assertSame('above', $flagged->context['direction']);
    }

    public function test_no_plan_means_no_plan_variance(): void
    {
        $tenant = $this->createTenant();
        // Sales only, no ingested plan.
        $this->seedPlanAndSales($tenant->id, 'PV-NOPLAN', null, 0, 40, []); // no-op seed
        foreach ([1, 2, 3] as $d) {
            SalesDaily::create([
                'tenant_id' => $tenant->id, 'sku' => 'PV-NOPLAN', 'store_id' => null,
                'date' => Carbon::today()->subDays($d)->toDateString(),
                'units_sold' => 40, 'revenue' => 0, 'transaction_count' => 1,
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($tenant->id);

        $this->assertDatabaseMissing('anomalies', [
            'tenant_id' => $tenant->id, 'rule_type' => 'plan_variance',
        ]);
    }

    /** @param array<int> $weeksAgo */
    private function seedPlannedOrders(int $tenantId, string $sku, float $qtyPerPoint, array $weeksAgo): void
    {
        foreach ($weeksAgo as $w) {
            PlanForecast::create([
                'tenant_id' => $tenantId, 'sku' => $sku, 'store_id' => null,
                'target_date' => Carbon::today()->subDays($w * 7)->toDateString(),
                'forecast_qty' => 0, 'planned_order_qty' => $qtyPerPoint, 'source' => 'relex',
            ]);
        }
    }

    public function test_order_plan_variance_flags_under_received_and_ignores_on_plan(): void
    {
        $tenant = $this->createTenant();

        // ORD-UNDER: planned 400 to order, only 100 received → 75% under → flags.
        $this->seedPlannedOrders($tenant->id, 'ORD-UNDER', 100, [0, 1, 2, 3]);
        PurchaseOrder::create([
            'tenant_id' => $tenant->id, 'sku' => 'ORD-UNDER', 'po_number' => 'PO-U', 'supplier' => 'S',
            'qty_ordered' => 100, 'qty_received' => 100,
            'order_date' => Carbon::today()->subDays(10)->toDateString(),
            'received_date' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        // ORD-OK: planned 400, received 390 → within tolerance → silent.
        $this->seedPlannedOrders($tenant->id, 'ORD-OK', 100, [0, 1, 2, 3]);
        PurchaseOrder::create([
            'tenant_id' => $tenant->id, 'sku' => 'ORD-OK', 'po_number' => 'PO-OK', 'supplier' => 'S',
            'qty_ordered' => 390, 'qty_received' => 390,
            'order_date' => Carbon::today()->subDays(10)->toDateString(),
            'received_date' => Carbon::today()->subDays(3)->toDateString(),
        ]);

        app(AnomalyDetectionService::class)->runForTenant($tenant->id);

        $flagged = Anomaly::where('tenant_id', $tenant->id)
            ->where('rule_type', 'order_plan_variance')->where('sku', 'ORD-UNDER')->first();
        $this->assertNotNull($flagged, 'receiving far below the planned order should flag');
        $this->assertSame('under', $flagged->context['direction']);

        $this->assertDatabaseMissing('anomalies', [
            'tenant_id' => $tenant->id, 'rule_type' => 'order_plan_variance', 'sku' => 'ORD-OK',
        ]);
    }

    public function test_order_plan_variance_needs_an_ingested_order_plan(): void
    {
        $tenant = $this->createTenant();

        // Forecast only (no planned_order_qty) + a receipt → nothing to compare against.
        PlanForecast::create([
            'tenant_id' => $tenant->id, 'sku' => 'FCST-ONLY', 'store_id' => null,
            'target_date' => Carbon::today()->subDays(2)->toDateString(),
            'forecast_qty' => 500, 'source' => 'relex',
        ]);
        PurchaseOrder::create([
            'tenant_id' => $tenant->id, 'sku' => 'FCST-ONLY', 'po_number' => 'PO-F', 'supplier' => 'S',
            'qty_ordered' => 10, 'qty_received' => 10,
            'order_date' => Carbon::today()->subDays(4)->toDateString(),
            'received_date' => Carbon::today()->subDays(2)->toDateString(),
        ]);

        app(AnomalyDetectionService::class)->runForTenant($tenant->id);

        $this->assertDatabaseMissing('anomalies', [
            'tenant_id' => $tenant->id, 'rule_type' => 'order_plan_variance',
        ]);
    }
}
