<?php

namespace Tests\Feature;

use App\Platform\Governance\ContractEvaluator;
use App\Platform\Governance\ContractRegistry;
use App\Platform\Trust\DataConfidence;
use Tests\TestCase;

/**
 * P4.2 — recommendation confidence is discounted when the feeding data breaches
 * its contract (P3.4), with reasons attached.
 */
class DataConfidenceTest extends TestCase
{
    private function breach(int $tenantId, string $feed, array $snapshot): void
    {
        app(ContractRegistry::class)->define($tenantId, $feed,
            requiredColumns: ['store', 'sku', 'qty'], freshnessSlaHours: 24, minRows: 100);
        app(ContractEvaluator::class)->evaluate($tenantId, $feed, $snapshot);
    }

    public function test_clean_feeds_do_not_discount(): void
    {
        $tenant = $this->createTenant();
        $this->breach($tenant->id, 'sales_daily', [
            'columns' => ['store', 'sku', 'qty'], 'row_count' => 5000, 'generated_at' => now()->subHour(),
        ]); // meets contract → no violation

        $adj = app(DataConfidence::class)->adjust(0.9, $tenant->id, ['sales_daily']);

        $this->assertSame(1.0, $adj->factor);
        $this->assertSame(0.9, $adj->adjusted);
        $this->assertFalse($adj->wasDiscounted());
    }

    public function test_stale_feed_discounts_confidence_with_a_reason(): void
    {
        $tenant = $this->createTenant();
        $this->breach($tenant->id, 'sales_daily', [
            'columns' => ['store', 'sku', 'qty'], 'row_count' => 5000, 'generated_at' => now()->subHours(48),
        ]); // stale only

        $adj = app(DataConfidence::class)->adjust(0.9, $tenant->id, ['sales_daily']);

        $this->assertSame(0.7, $adj->factor);            // 1.0 − 0.30 (stale)
        $this->assertSame(0.63, $adj->adjusted);         // 0.9 × 0.7
        $this->assertTrue($adj->wasDiscounted());
        $this->assertContains('sales_daily: stale', $adj->reasons);
    }

    public function test_multiple_breaches_compound_but_never_below_the_floor(): void
    {
        $tenant = $this->createTenant();
        // missing column + empty → 0.30 + 0.50 = 0.80 penalty → 0.20 factor (floored)
        $this->breach($tenant->id, 'inventory', [
            'columns' => ['store'], 'row_count' => 0, 'generated_at' => now()->subHour(),
        ]);

        $adj = app(DataConfidence::class)->adjust(1.0, $tenant->id, ['inventory']);

        $this->assertSame(0.2, $adj->factor); // floored, not below
        $this->assertGreaterThanOrEqual(2, count($adj->reasons));
    }

    public function test_only_the_named_feeds_and_tenant_count(): void
    {
        $tenant = $this->createTenant();
        $this->breach($tenant->id, 'sales_daily', [
            'columns' => ['store', 'sku', 'qty'], 'row_count' => 5000, 'generated_at' => now()->subHours(48),
        ]); // stale on sales_daily

        // A recommendation that depends only on 'promotions' is unaffected by sales_daily's breach.
        $adj = app(DataConfidence::class)->adjust(0.8, $tenant->id, ['promotions']);
        $this->assertSame(1.0, $adj->factor);
    }
}
