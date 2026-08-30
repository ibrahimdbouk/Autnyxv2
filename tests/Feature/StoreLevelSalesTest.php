<?php

namespace Tests\Feature;

use App\Models\AnomalySetting;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use Tests\TestCase;

/**
 * Detection quality — the optional store-level sales pass. A single store's swing
 * is diluted by the tenant-wide comparison; the store-level pass catches it, but
 * only when the tenant opts in (`store_level` threshold), and only above the
 * absolute-units floor.
 */
class StoreLevelSalesTest extends TestCase
{
    private function historicalOnly(Tenant $t, Store $store, string $sku): void
    {
        // Sales sit in the historical window (8–35 days ago) with nothing recent —
        // a clear drop at this store.
        foreach ([14, 21, 28] as $daysAgo) {
            SalesTransaction::create([
                'tenant_id' => $t->id,
                'store_id'  => $store->id,
                'sku'       => $sku,
                'quantity'  => 30,
                'date'      => now()->subDays($daysAgo)->toDateString(),
            ]);
        }
    }

    public function test_store_level_pass_flags_a_single_store_swing_when_enabled(): void
    {
        $t     = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'A', 'code' => 'STA']);
        $this->historicalOnly($t, $store, 'XSTORE');

        AnomalySetting::seedForTenant($t->id);
        AnomalySetting::where('tenant_id', $t->id)->where('rule_type', 'sales_drop')
            ->update(['thresholds' => ['store_level' => true, 'store_min_units' => 5, 'pct' => 30, 'days' => 7]]);

        app(AnomalyDetectionService::class)->runForTenant($t->id);

        $this->assertDatabaseHas('anomalies', [
            'tenant_id' => $t->id, 'rule_type' => 'sales_drop', 'sku' => 'XSTORE', 'store_id' => $store->id,
        ]);
    }

    public function test_store_level_pass_is_off_by_default(): void
    {
        $t     = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'A', 'code' => 'STA']);
        $this->historicalOnly($t, $store, 'XSTORE');

        app(AnomalyDetectionService::class)->runForTenant($t->id); // default settings — no store_level

        $this->assertDatabaseMissing('anomalies', [
            'tenant_id' => $t->id, 'rule_type' => 'sales_drop', 'sku' => 'XSTORE', 'store_id' => $store->id,
        ]);
    }
}
