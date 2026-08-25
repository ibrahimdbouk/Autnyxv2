<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\SalesDaily;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use Tests\TestCase;

/**
 * B1: the tenant currency labels anomaly descriptions (display-only, no
 * conversion), and the stockout materiality floor suppresses trivial-value
 * stockouts while keeping the ones worth money.
 */
class CurrencyAndStockoutFloorTest extends TestCase
{
    public function test_tenant_currency_defaults_and_formats(): void
    {
        $tenant = $this->createTenant(); // no currency set → DB default
        $this->assertSame('AED', $tenant->currencyCode());

        $eur = $this->createTenant(['currency' => 'EUR']);
        $this->assertSame('€1,000.00', $eur->money(1000));
    }

    public function test_stockout_description_uses_tenant_currency(): void
    {
        $tenant = $this->createTenant(['currency' => 'EUR']);
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S', 'code' => 'ST01']);
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'CUR-1', 'name' => 'Seller', 'selling_price' => 100]);

        InventoryLevel::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => 'CUR-1',
            'on_hand_qty' => 0, 'as_of_date' => now()->subDays(2)->format('Y-m-d'),
        ]);
        for ($i = 1; $i <= 10; $i++) {
            SalesDaily::create([
                'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => 'CUR-1',
                'date' => now()->subDays($i)->format('Y-m-d'),
                'units_sold' => 15, 'revenue' => 1500, 'transaction_count' => 1,
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($tenant->id);

        $anomaly = Anomaly::where('tenant_id', $tenant->id)->where('rule_type', 'stockout_risk')->where('sku', 'CUR-1')->first();
        $this->assertNotNull($anomaly);
        $this->assertStringContainsString('€', $anomaly->description, 'stockout description should be labelled in the tenant currency');
        $this->assertStringNotContainsString('$', $anomaly->description);
    }

    public function test_low_value_stockout_is_floored_but_valuable_one_flags(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S', 'code' => 'ST01']);

        // Cheap item: a few units of a ~1-currency SKU → lost-sales value well under the 100 floor.
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'CHEAP', 'name' => 'Cheap', 'selling_price' => 1]);
        // Valuable item: same low velocity but a high price → clears the floor.
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'RICH', 'name' => 'Rich', 'selling_price' => 500]);

        foreach (['CHEAP', 'RICH'] as $sku) {
            InventoryLevel::create([
                'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => $sku,
                'on_hand_qty' => 0, 'as_of_date' => now()->subDays(2)->format('Y-m-d'),
            ]);
            // 5 units total over the window (≥ min_units 3, but low daily rate).
            SalesDaily::create([
                'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => $sku,
                'date' => now()->subDays(3)->format('Y-m-d'),
                'units_sold' => 5, 'revenue' => 5, 'transaction_count' => 1,
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($tenant->id);

        $this->assertNull(
            Anomaly::where('tenant_id', $tenant->id)->where('rule_type', 'stockout_risk')->where('sku', 'CHEAP')->first(),
            'a stockout worth only a few currency units should be below the default floor'
        );
        $this->assertNotNull(
            Anomaly::where('tenant_id', $tenant->id)->where('rule_type', 'stockout_risk')->where('sku', 'RICH')->first(),
            'the same velocity on a high-priced item clears the floor and flags'
        );
    }
}
