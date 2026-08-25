<?php

namespace Tests\Feature;

use App\Models\InventoryLevel;
use App\Models\SalesDaily;
use App\Models\SkuProfile;
use App\Models\Store;
use App\Services\Anomaly\SkuProfilerService;
use Tests\TestCase;

class SkuProfilerTest extends TestCase
{
    public function test_classifies_demand_shapes_and_dead_stock(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S', 'code' => 'ST01']);

        $daily = function (string $sku, int $daysAgo, float $units) use ($tenant, $store) {
            SalesDaily::create([
                'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => $sku,
                'date' => now()->subDays($daysAgo)->format('Y-m-d'),
                'units_sold' => $units, 'revenue' => $units * 10, 'transaction_count' => 1,
            ]);
        };

        for ($i = 1; $i <= 90; $i++) {
            // SMOOTH: sells daily, steady size.
            $daily('SMOOTH', $i, 10);
            // ERRATIC: sells daily, wildly variable size.
            $daily('ERRATIC', $i, $i % 2 === 0 ? 5 : 80);
            // INTERMITTENT: sells ~every 5th day, steady size.
            if ($i % 5 === 0) $daily('INTERMIT', $i, 10);
            // LUMPY: sells ~every 5th day, variable size.
            if ($i % 5 === 0) $daily('LUMPY', $i, $i % 10 === 0 ? 80 : 5);
        }

        // DEAD: stock on hand, no sales at all.
        InventoryLevel::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => 'DEAD',
            'on_hand_qty' => 50, 'as_of_date' => now()->subDay()->format('Y-m-d'),
        ]);

        app(SkuProfilerService::class)->profileForTenant($tenant->id, 90);

        $seg = fn (string $sku) => SkuProfile::where('tenant_id', $tenant->id)
            ->where('sku', $sku)->where('store_id', $store->id)->value('segment');

        $this->assertSame(SkuProfile::SEG_SMOOTH, $seg('SMOOTH'));
        $this->assertSame(SkuProfile::SEG_ERRATIC, $seg('ERRATIC'));
        $this->assertSame(SkuProfile::SEG_INTERMITTENT, $seg('INTERMIT'));
        $this->assertSame(SkuProfile::SEG_LUMPY, $seg('LUMPY'));
        $this->assertSame(SkuProfile::SEG_DEAD, $seg('DEAD'));

        // A smooth daily seller should be assigned a moving-average model.
        $this->assertSame(
            SkuProfile::MODEL_MOVING_AVERAGE,
            SkuProfile::where('tenant_id', $tenant->id)->where('sku', 'SMOOTH')->value('chosen_model')
        );
    }
}
