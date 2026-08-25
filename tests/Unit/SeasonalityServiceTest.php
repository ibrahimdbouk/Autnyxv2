<?php

namespace Tests\Unit;

use App\Models\SalesDaily;
use App\Models\Store;
use App\Services\Anomaly\SeasonalityService;
use Carbon\Carbon;
use Tests\TestCase;

class SeasonalityServiceTest extends TestCase
{
    public function test_day_of_week_factors_capture_a_weekend_heavy_rhythm(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S', 'code' => 'ST01']);

        // 8 weeks of history: Saturdays sell 100, every other day sells 10.
        for ($i = 1; $i <= 56; $i++) {
            $date = Carbon::today()->subDays($i);
            SalesDaily::create([
                'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => 'WKND',
                'date' => $date->format('Y-m-d'),
                'units_sold' => $date->dayOfWeek === Carbon::SATURDAY ? 100 : 10,
                'revenue' => 0, 'transaction_count' => 1,
            ]);
        }

        $factors = (new SeasonalityService())->dayOfWeekFactors($tenant->id, 90);

        $this->assertNotEmpty($factors);
        // Saturday (Carbon/Postgres DOW 6) is far busier than a weekday.
        $this->assertGreaterThan(3.0, $factors[6], 'Saturday should carry a high factor');
        $this->assertLessThan(0.7, $factors[1], 'Monday should carry a low factor');
    }

    public function test_expected_units_over_a_full_week_is_roughly_neutral(): void
    {
        $svc = new SeasonalityService();
        // Uniform factors → expected == baseline × number of days.
        $flat = [0 => 1.0, 1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 1.0, 6 => 1.0];
        $dates = [];
        for ($i = 1; $i <= 7; $i++) $dates[] = Carbon::today()->subDays($i)->format('Y-m-d');

        $this->assertEqualsWithDelta(70.0, $svc->expectedUnits(10.0, $flat, $dates), 0.01);
        // Empty factors → falls back to flat baseline × days.
        $this->assertEqualsWithDelta(70.0, $svc->expectedUnits(10.0, [], $dates), 0.01);
    }

    public function test_has_seasonal_history_is_false_on_a_few_months(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S', 'code' => 'ST01']);
        for ($i = 1; $i <= 60; $i++) {
            SalesDaily::create([
                'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => 'X',
                'date' => Carbon::today()->subDays($i)->format('Y-m-d'),
                'units_sold' => 5, 'revenue' => 0, 'transaction_count' => 1,
            ]);
        }

        $this->assertFalse((new SeasonalityService())->hasSeasonalHistory($tenant->id));
    }
}
