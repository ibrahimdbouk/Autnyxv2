<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Product;
use App\Models\SalesDaily;
use App\Models\SkuProfile;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use Tests\TestCase;

/**
 * Phase 4+5: the Croston/SBA demand-vs-forecast rule for intermittent SKUs.
 * A real burst above the fitted forecast flags; an ordinary intermittent gap
 * (no recent sale) does NOT — the shortfall guard requires the item to normally
 * sell ≥2× in the window.
 */
class DemandForecastTest extends TestCase
{
    private Tenant $tenant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->store  = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S', 'code' => 'ST01']);
    }

    private function intermittentSku(string $sku): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => $sku, 'selling_price' => 100]);
        SkuProfile::create([
            'tenant_id' => $this->tenant->id, 'sku' => $sku, 'store_id' => 0,
            'segment' => SkuProfile::SEG_INTERMITTENT, 'cv2' => 0.1,
        ]);
        // Baseline: ~7 demand events of 10 units, roughly every 10 days.
        foreach ([70, 60, 50, 40, 30, 21, 14] as $d) {
            SalesDaily::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'sku' => $sku,
                'date' => now()->subDays($d)->format('Y-m-d'),
                'units_sold' => 10, 'revenue' => 1000, 'transaction_count' => 1,
            ]);
        }
    }

    public function test_burst_above_forecast_flags_but_normal_gap_does_not(): void
    {
        $this->intermittentSku('FCAST-HI');   // will get a big recent burst
        $this->intermittentSku('FCAST-GAP');  // no recent sale — a normal gap

        // Recent burst for HI: 40 units in the last week vs a forecast of ~1 unit/day.
        SalesDaily::create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'sku' => 'FCAST-HI',
            'date' => now()->subDays(3)->format('Y-m-d'),
            'units_sold' => 40, 'revenue' => 4000, 'transaction_count' => 1,
        ]);

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'demand_forecast_break')->where('sku', 'FCAST-HI')->first(),
            'a burst far above the best-fit forecast should flag'
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'demand_forecast_break')->where('sku', 'FCAST-GAP')->first(),
            'an ordinary intermittent gap (no recent sale) must NOT flag'
        );
    }
}
