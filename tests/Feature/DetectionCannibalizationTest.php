<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\Product;
use App\Models\SalesDaily;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use Tests\TestCase;

/**
 * Store-level, candidate-based cannibalization detection (Phase 1).
 * Verifies correctness (store isolation, category-demand guard, share signal),
 * tenant isolation, and idempotent re-runs.
 */
class DetectionCannibalizationTest extends TestCase
{
    private Tenant $tenant;
    private string $recent;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->recent = now()->subDays(5)->format('Y-m-d');   // recent window
        $this->base   = now()->subDays(45)->format('Y-m-d');  // prior window
    }

    private function store(string $name): int
    {
        return Store::create(['tenant_id' => $this->tenant->id, 'name' => $name])->id;
    }

    private function product(string $sku, ?string $category, ?Tenant $tenant = null): void
    {
        Product::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'sku'       => $sku,
            'name'      => $sku,
            'category'  => $category,
        ]);
    }

    private function daily(int $storeId, string $sku, string $date, float $units, ?Tenant $tenant = null): void
    {
        SalesDaily::create([
            'tenant_id'         => ($tenant ?? $this->tenant)->id,
            'store_id'          => $storeId,
            'sku'               => $sku,
            'date'              => $date,
            'units_sold'        => $units,
            'revenue'           => $units,
            'transaction_count' => 1,
        ]);
    }

    private function detect(?Tenant $tenant = null): void
    {
        app(AnomalyDetectionService::class)->runForTenant(($tenant ?? $this->tenant)->id);
    }

    private function cannibalization(string $sku, ?int $storeId = null): ?Anomaly
    {
        $q = Anomaly::where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'cannibalization_signal')
            ->where('sku', $sku);
        if ($storeId !== null) $q->where('store_id', $storeId);

        return $q->first();
    }

    public function test_positive_cannibalization_is_flagged_with_share_and_primary(): void
    {
        $s1 = $this->store('S1');
        $this->product('A', 'Beverages');
        $this->product('B', 'Beverages');

        $this->daily($s1, 'A', $this->base, 100);
        $this->daily($s1, 'A', $this->recent, 200); // +100%
        $this->daily($s1, 'B', $this->base, 100);
        $this->daily($s1, 'B', $this->recent, 40);  // -60%; category +20% (stable)

        $this->detect();

        $a = $this->cannibalization('B', $s1);
        $this->assertNotNull($a, 'affected SKU should be flagged');
        $this->assertSame('A', $a->context['primary_sku']);
        $this->assertGreaterThan(0, $a->context['primary_share_change_pct']);
        $this->assertLessThan(0, $a->context['affected_share_change_pct']);
    }

    public function test_category_wide_decline_is_not_cannibalization(): void
    {
        $s1 = $this->store('S1');
        $this->product('C', 'Snacks');
        $this->product('D', 'Snacks');

        $this->daily($s1, 'C', $this->base, 100);
        $this->daily($s1, 'C', $this->recent, 130); // +30%
        $this->daily($s1, 'D', $this->base, 300);
        $this->daily($s1, 'D', $this->recent, 60);  // -80%; category 400→190 = -52% (declining)

        $this->detect();

        $this->assertNull($this->cannibalization('D'), 'category decline must not be flagged as cannibalization');
    }

    public function test_rise_and_fall_in_different_stores_are_not_linked(): void
    {
        $s1 = $this->store('S1');
        $s2 = $this->store('S2');
        $this->product('A', 'Beverages');
        $this->product('B', 'Beverages');

        $this->daily($s1, 'A', $this->base, 100);
        $this->daily($s1, 'A', $this->recent, 200); // A up in S1 only
        $this->daily($s2, 'B', $this->base, 100);
        $this->daily($s2, 'B', $this->recent, 40);  // B down in S2 only

        $this->detect();

        $this->assertNull($this->cannibalization('B'), 'store-level detection must not link across stores');
    }

    public function test_multiple_falling_siblings_each_flagged(): void
    {
        $s1 = $this->store('S1');
        $this->product('A', 'Dairy');
        $this->product('B', 'Dairy');
        $this->product('E', 'Dairy');

        $this->daily($s1, 'A', $this->base, 100);
        $this->daily($s1, 'A', $this->recent, 300); // +200%
        $this->daily($s1, 'B', $this->base, 100);
        $this->daily($s1, 'B', $this->recent, 50);  // -50%
        $this->daily($s1, 'E', $this->base, 100);
        $this->daily($s1, 'E', $this->recent, 55);  // -45%

        $this->detect();

        $this->assertNotNull($this->cannibalization('B'));
        $this->assertNotNull($this->cannibalization('E'));
    }

    public function test_missing_category_excludes_sku(): void
    {
        $s1 = $this->store('S1');
        $this->product('A', 'Beverages');
        // B has no product record → uncategorised → cannot be a sibling.
        $this->daily($s1, 'A', $this->base, 100);
        $this->daily($s1, 'A', $this->recent, 200);
        $this->daily($s1, 'B', $this->base, 100);
        $this->daily($s1, 'B', $this->recent, 40);

        $this->detect();

        $this->assertNull($this->cannibalization('B'));
    }

    public function test_threshold_change_suppresses_weak_movement(): void
    {
        // Raise the deviation threshold to 50%: a +40% riser is no longer a candidate.
        AnomalySetting::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'rule_type' => 'cannibalization_signal'],
            ['enabled' => true, 'thresholds' => ['pct' => 50, 'days' => 30]]
        );

        $s1 = $this->store('S1');
        $this->product('A', 'Beverages');
        $this->product('B', 'Beverages');
        $this->daily($s1, 'A', $this->base, 100);
        $this->daily($s1, 'A', $this->recent, 140); // +40% (< 50 threshold)
        $this->daily($s1, 'B', $this->base, 100);
        $this->daily($s1, 'B', $this->recent, 45);  // -55%

        $this->detect();

        $this->assertNull($this->cannibalization('B'), 'no positive candidate above threshold → no signal');
    }

    public function test_duplicate_runs_do_not_duplicate_signals(): void
    {
        $s1 = $this->store('S1');
        $this->product('A', 'Beverages');
        $this->product('B', 'Beverages');
        $this->daily($s1, 'A', $this->base, 100);
        $this->daily($s1, 'A', $this->recent, 200);
        $this->daily($s1, 'B', $this->base, 100);
        $this->daily($s1, 'B', $this->recent, 40);

        $this->detect();
        $this->detect();

        $count = Anomaly::where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'cannibalization_signal')
            ->where('sku', 'B')->count();
        $this->assertSame(1, $count);
    }

    public function test_tenant_isolation(): void
    {
        $other = $this->createTenant();
        $otherStore = Store::create(['tenant_id' => $other->id, 'name' => 'OS'])->id;
        $this->product('A', 'Beverages', $other);
        $this->product('B', 'Beverages', $other);
        $this->daily($otherStore, 'A', $this->base, 100, $other);
        $this->daily($otherStore, 'A', $this->recent, 200, $other);
        $this->daily($otherStore, 'B', $this->base, 100, $other);
        $this->daily($otherStore, 'B', $this->recent, 40, $other);

        // Run detection for our tenant only — the other tenant's movement must not leak.
        $this->detect();

        $this->assertSame(0, Anomaly::where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'cannibalization_signal')->count());
    }

    public function test_empty_dataset_is_safe(): void
    {
        $this->detect();
        $this->assertSame(0, Anomaly::where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'cannibalization_signal')->count());
    }
}
