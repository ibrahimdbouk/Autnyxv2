<?php

namespace Tests\Feature;

use App\Models\SkuBaseline;
use App\Models\Tenant;
use App\Services\Anomaly\BaselineCalculatorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP4.2 (audit H15) — baselines from the densified daily series, the test
 * window excluded, median / MAD, stale rows deleted, sensitivity drifting back.
 */
class RobustBaselinesTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['settings' => ['detection_rules_v2' => true]]);
    }

    private function sell(string $sku, int $ago, float $units): void
    {
        DB::table('sales_daily')->insert(['tenant_id' => $this->tenant->id, 'store_id' => null, 'sku' => $sku,
            'date' => Carbon::today()->subDays($ago)->format('Y-m-d'), 'units_sold' => $units, 'revenue' => 0, 'transaction_count' => 1,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function compute(): void
    {
        app(BaselineCalculatorService::class)->computeForTenant($this->tenant->id);
    }

    private function baseline(string $sku): ?SkuBaseline
    {
        return SkuBaseline::where('tenant_id', $this->tenant->id)->where('sku', $sku)->where('rule_type', 'sales_drop')->whereNull('store_id')->first();
    }

    public function test_the_days_being_tested_are_not_part_of_their_own_baseline(): void
    {
        foreach (range(96, 7) as $ago) {
            $this->sell('A', $ago, $ago % 3 === 0 ? 11 : 10);
        }
        foreach (range(6, 0) as $ago) {
            $this->sell('A', $ago, 40); // the spike under test
        }

        $this->compute();

        $this->assertEqualsWithDelta(10, $this->baseline('A')->baseline_mean, 0.001, 'median of the 90 days before the test window');
        $this->assertSame(90, (int) $this->baseline('A')->sample_count);
    }

    public function test_days_without_sales_count_as_zero_from_the_first_sale(): void
    {
        foreach (range(60, 7) as $ago) {
            if ($ago % 2 === 0) {
                $this->sell('B', $ago, 10);
            }
        }
        $this->sell('B', 0, 10);

        $this->compute();

        $b = $this->baseline('B');
        $this->assertSame(54, (int) $b->sample_count, 'from its first sale (60 days ago) to the test window, zeros included');
        $this->assertEqualsWithDelta(5, $b->baseline_mean, 0.001, 'half the days sell 10, half sell nothing');
        $this->assertGreaterThan(0, $b->baseline_stddev);
    }

    public function test_baselines_that_no_longer_qualify_are_deleted_and_sensitivity_drifts_back(): void
    {
        foreach (range(40, 7) as $ago) {
            $this->sell('C', $ago, $ago % 2 ? 9 : 12);
        }
        foreach (['GONE', 'C'] as $sku) {
            SkuBaseline::create(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'store_id' => null, 'rule_type' => 'sales_drop',
                'metric' => 'daily_sales_qty', 'baseline_mean' => 5, 'baseline_stddev' => 1, 'sample_count' => 30,
                'sensitivity_multiplier' => 4.0, 'fp_count' => 3, 'computed_at' => now()->subDay()]);
        }

        $this->compute();

        $this->assertNull($this->baseline('GONE'));
        $c = $this->baseline('C');
        $this->assertEqualsWithDelta(3.9, $c->sensitivity_multiplier, 0.0001, '2 + (4 − 2) × 0.95');
        $this->assertSame(1, SkuBaseline::where('sku', 'C')->where('rule_type', 'sales_drop')->count(), 'updated in place, not duplicated');
    }
}
