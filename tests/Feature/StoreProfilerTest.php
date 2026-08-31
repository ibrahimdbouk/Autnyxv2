<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\SkuProfile;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Platform\Intelligence\StoreProfiler;
use Tests\TestCase;

/**
 * Phase 2 — the store feature layer. Features are computed from sales + products
 * + sku_profiles, and tiers are assigned relative to the tenant's own stores.
 */
class StoreProfilerTest extends TestCase
{
    private function store(int $tenantId, string $code): Store
    {
        return Store::create(['tenant_id' => $tenantId, 'name' => $code, 'code' => $code]);
    }

    private function sale(int $tenantId, Store $store, string $sku, float $qty, float $amount, float $discount, string $txn, int $daysAgo): void
    {
        SalesTransaction::create([
            'tenant_id' => $tenantId, 'store_id' => $store->id, 'sku' => $sku,
            'quantity' => $qty, 'total_amount' => $amount, 'discount' => $discount,
            'transaction_id' => $txn, 'date' => now()->subDays($daysAgo)->toDateString(),
        ]);
    }

    public function test_computes_features_and_tenant_relative_tiers(): void
    {
        $t = $this->createTenant();

        Product::create(['tenant_id' => $t->id, 'sku' => 'P-A', 'name' => 'A', 'category' => 'Grocery']);
        Product::create(['tenant_id' => $t->id, 'sku' => 'P-B', 'name' => 'B', 'category' => 'Grocery']);
        Product::create(['tenant_id' => $t->id, 'sku' => 'P-C', 'name' => 'C', 'category' => 'Beverages']);

        $s1 = $this->store($t->id, 'S1'); // small / value / low
        $s2 = $this->store($t->id, 'S2'); // medium / mid / mid
        $s3 = $this->store($t->id, 'S3'); // large / premium / high
        $s4 = $this->store($t->id, 'S4'); // no sales

        // S1: one basket, revenue 100
        $this->sale($t->id, $s1, 'P-A', 2, 100, 0, 'T1', 10);

        // S2: two baskets across two windows (for growth), revenue 500, one promo
        $this->sale($t->id, $s2, 'P-A', 4, 400, 0, 'T2', 10);   // recent 30d
        $this->sale($t->id, $s2, 'P-B', 2, 100, 10, 'T3', 45);  // prior 30–60d, discounted

        // S3: one basket, revenue 2000
        $this->sale($t->id, $s3, 'P-C', 10, 2000, 0, 'T4', 10);

        // Demand shape for S2
        SkuProfile::create(['tenant_id' => $t->id, 'store_id' => $s2->id, 'sku' => 'P-A', 'segment' => 'smooth']);
        SkuProfile::create(['tenant_id' => $t->id, 'store_id' => $s2->id, 'sku' => 'P-B', 'segment' => 'smooth']);

        $profiled = app(StoreProfiler::class)->rebuild($t->id);
        $this->assertSame(4, $profiled);

        $f2 = StoreFeature::where('store_id', $s2->id)->firstOrFail();
        $this->assertSame(500.0, $f2->revenue);
        $this->assertSame(6.0, $f2->units);
        $this->assertSame(2, $f2->active_skus);
        $this->assertSame(2, $f2->basket_count);
        $this->assertSame(250.0, $f2->avg_basket_value);
        $this->assertSame(3.0, $f2->avg_basket_units);
        $this->assertEqualsWithDelta(83.3333, $f2->avg_selling_price, 0.001);
        $this->assertSame(250.0, $f2->sku_productivity);
        $this->assertSame(0.2, $f2->promo_share);           // 100 of 500 discounted
        $this->assertSame(4.0, $f2->growth_ratio);          // 400 recent / 100 prior
        $this->assertSame('Grocery', $f2->top_category);
        $this->assertSame(1.0, $f2->top_category_share);
        $this->assertSame('smooth', $f2->dominant_segment);

        // Tiers are relative to this tenant's stores.
        $this->assertSame('small', StoreFeature::where('store_id', $s1->id)->value('size_tier'));
        $this->assertSame('medium', $f2->size_tier);
        $this->assertSame('large', StoreFeature::where('store_id', $s3->id)->value('size_tier'));
        $this->assertSame('value', StoreFeature::where('store_id', $s1->id)->value('price_tier'));
        $this->assertSame('premium', StoreFeature::where('store_id', $s3->id)->value('price_tier'));
        $this->assertSame('Medium-format · mid · mid-basket', $f2->descriptor);
    }

    public function test_store_with_no_sales_is_marked_no_recent_sales(): void
    {
        $t = $this->createTenant();
        $a = $this->store($t->id, 'A');
        $b = $this->store($t->id, 'B');
        $c = $this->store($t->id, 'C');
        $this->sale($t->id, $a, 'P-A', 1, 50, 0, 'T1', 5);
        $this->sale($t->id, $b, 'P-A', 1, 60, 0, 'T2', 5);
        // c has no sales

        app(StoreProfiler::class)->rebuild($t->id);

        $fc = StoreFeature::where('store_id', $c->id)->firstOrFail();
        $this->assertSame(0.0, $fc->revenue);
        $this->assertNull($fc->size_tier);
        $this->assertSame('No recent sales', $fc->descriptor);
    }
}
