<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Anomaly\ThresholdRecommenderService;
use Tests\TestCase;

/**
 * B3: recommended materiality floors are derived from the tenant's own price
 * and cost distribution — so a new tenant starts calibrated, not blank.
 */
class ThresholdRecommenderTest extends TestCase
{
    public function test_floors_scale_to_catalogue_economics(): void
    {
        $tenant = $this->createTenant();

        // Median selling price 30, median unit cost 25.
        foreach ([[10, 5], [20, 15], [30, 25], [40, 35], [50, 45]] as [$price, $cost]) {
            Product::create([
                'tenant_id' => $tenant->id, 'sku' => "P{$price}", 'name' => "P{$price}",
                'selling_price' => $price, 'unit_cost' => $cost,
            ]);
        }

        $rec = app(ThresholdRecommenderService::class)->recommendForTenant($tenant->id);

        $this->assertEqualsWithDelta(30, $rec['economics']['median_price'], 0.01);
        $this->assertEqualsWithDelta(25, $rec['economics']['median_cost'], 0.01);

        // Revenue floor ≈ nice-rounded max(100, 30*5=150) = 150.
        $this->assertSame(150.0, (float) $rec['rules']['stockout_risk']['recommended']);
        $this->assertSame('min_revenue', $rec['rules']['stockout_risk']['key']);

        // Value floor ≈ nice-rounded max(250, 25*20=500) = 500.
        $this->assertSame(500.0, (float) $rec['rules']['slow_moving_capital']['recommended']);
        $this->assertSame('min_value', $rec['rules']['slow_moving_capital']['key']);
    }

    public function test_empty_catalogue_uses_sane_minimums(): void
    {
        $tenant = $this->createTenant();

        $rec = app(ThresholdRecommenderService::class)->recommendForTenant($tenant->id);

        // No products → floors fall back to their minimums, not zero.
        $this->assertSame(100.0, (float) $rec['rules']['stockout_risk']['recommended']);
        $this->assertSame(250.0, (float) $rec['rules']['slow_moving_capital']['recommended']);
    }
}
