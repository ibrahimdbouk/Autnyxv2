<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Product;
use App\Models\SalesDaily;
use App\Models\SkuProfile;
use App\Models\Store;
use App\Services\Anomaly\ForecastChartService;
use Tests\TestCase;

/**
 * The forecast chart builds a baseline+forecast series for a demand_forecast_break
 * anomaly and renders it as inline SVG; it is null for other rules.
 */
class ForecastChartServiceTest extends TestCase
{
    public function test_builds_series_and_svg_for_forecast_anomaly(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S', 'code' => 'ST01']);
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'FC1', 'name' => 'Item', 'selling_price' => 50]);
        SkuProfile::create(['tenant_id' => $tenant->id, 'sku' => 'FC1', 'store_id' => 0,
            'segment' => SkuProfile::SEG_INTERMITTENT, 'cv2' => 0.3, 'mean_nonzero' => 10, 'adi' => 5]);

        // Intermittent demand over the window: a sale every ~5 days.
        foreach ([40, 35, 30, 25, 20, 14, 8, 3] as $d) {
            SalesDaily::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => 'FC1',
                'date' => now()->subDays($d)->format('Y-m-d'), 'units_sold' => 10, 'revenue' => 500, 'transaction_count' => 1]);
        }

        $anomaly = Anomaly::create(['tenant_id' => $tenant->id, 'rule_type' => 'demand_forecast_break',
            'severity' => 'medium', 'sku' => 'FC1', 'store_id' => null,
            'description' => 'forecast break', 'context' => ['segment' => 'intermittent', 'alpha' => 0.2], 'detected_at' => now()]);

        $svc = app(ForecastChartService::class);

        $series = $svc->seriesForAnomaly($anomaly);
        $this->assertNotNull($series);
        $this->assertCount(56, $series['dates']);
        $this->assertCount(56, $series['actual']);
        $this->assertSame(49, $series['recentFrom']);
        $this->assertGreaterThan(0, $series['rate']);

        $svg = $svc->svgForAnomaly($anomaly);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<polyline', $svg);
    }

    public function test_null_for_non_forecast_anomaly(): void
    {
        $tenant = $this->createTenant();
        $anomaly = Anomaly::create(['tenant_id' => $tenant->id, 'rule_type' => 'stockout_risk',
            'severity' => 'high', 'sku' => 'X', 'description' => 'x', 'detected_at' => now()]);

        $this->assertNull(app(ForecastChartService::class)->seriesForAnomaly($anomaly));
        $this->assertNull(app(ForecastChartService::class)->svgForAnomaly($anomaly));
    }
}
