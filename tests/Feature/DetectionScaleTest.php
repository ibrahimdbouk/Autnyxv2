<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\DetectionDirtyKey;
use App\Models\Tenant;
use App\Services\Detection\RunScope;
use App\Services\Detection\TenantDetectionRunner;
use App\Services\Inventory\InventoryCurrentService;
use App\Support\Testing\SyntheticRetailer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP6.3 (audit H31) — detection that scales: a big tenant's full run is split
 * into SKU buckets with the same result as one pass; a scoped run never clears
 * what it did not look at; SKU lists travel as one array parameter; every
 * rule's cost is measured.
 */
class DetectionScaleTest extends TestCase
{
    private function syntheticTenant(): Tenant
    {
        $t = $this->createTenant(['status' => 'active', 'settings' => ['detection_rules_v2' => true]]);
        SyntheticRetailer::seed($t->id, stores: 4, skus: 60, days: 60, signalEvery: 8);
        app(InventoryCurrentService::class)->rebuild($t->id);
        foreach (['sku:profile', 'baselines:compute'] as $cmd) {
            $this->artisan($cmd, ['--tenant' => $t->id]);
        }

        return $t;
    }

    /** @return array<int,string> "rule|sku|store code|subject|severity|state" */
    private function outcome(Tenant $t): array
    {
        $codes = DB::table('stores')->where('tenant_id', $t->id)->pluck('code', 'id');

        return Anomaly::where('tenant_id', $t->id)->whereNull('dismissed_at')->get()
            ->map(fn ($a) => implode('|', [$a->rule_type, $a->sku, $codes[$a->store_id] ?? '-', $a->context['subject'] ?? '-', $a->severity, $a->lifecycle_state]))
            ->sort()->values()->all();
    }

    public function test_a_bucketed_full_run_finds_exactly_what_one_pass_finds(): void
    {
        $single = $this->syntheticTenant();
        $split  = $this->syntheticTenant();
        $runner = app(TenantDetectionRunner::class);
        $this->freezeTime();   // the lifecycle compares timestamps; same clock for both tenants

        $this->assertNull($runner->buckets($single->id), '240 positions: one pass');
        $runner->runFull($single->id);

        config(['detection.bucket_positions' => 40]);
        $buckets = $runner->buckets($split->id);
        $this->assertGreaterThan(3, count($buckets), 'split into SKU buckets of ~10 SKUs × 4 stores');
        $runner->runFull($split->id);

        $a = $this->outcome($single);
        $this->assertGreaterThan(20, count($a), 'the synthetic retailer has plenty of signals');
        $this->assertGreaterThan(5, count(array_unique(array_map(fn ($r) => strtok($r, '|'), $a))), 'across many rules');
        $this->assertSame($a, $this->outcome($split));

        // Twice more, an hour apart: the lifecycle (open → persisting, clearing) advances the same way.
        foreach ([1, 2] as $_) {
            $this->travel(1)->hours();
            config(['detection.bucket_positions' => 250000]);
            $runner->runFull($single->id);
            config(['detection.bucket_positions' => 40]);
            $runner->runFull($split->id);
            $this->assertSame($this->outcome($single), $this->outcome($split));
        }
        $this->assertContains('persisting', array_map(fn ($r) => substr($r, strrpos($r, '|') + 1), $this->outcome($split)));
        $this->assertArrayHasKey('stockout_risk', $runner->lastRuleStats());
    }

    public function test_a_scoped_run_never_clears_a_subject_it_did_not_look_at(): void
    {
        $t = $this->createTenant(['status' => 'active', 'settings' => ['detection_rules_v2' => true]]);
        $store = \App\Models\Store::create(['tenant_id' => $t->id, 'name' => 'Mall']);
        $shrink = Anomaly::create(['tenant_id' => $t->id, 'rule_type' => 'inventory_shrinkage', 'severity' => 'high', 'sku' => 'X',
            'store_id' => $store->id, 'description' => 'x', 'context' => [], 'detected_at' => now()->subDay()]);
        $before = $shrink->fresh()->only(['lifecycle_state', 'clear_streak', 'cleared_at']);

        // One bucket of a bucketed full run: SKUs Y only.
        app(\App\Services\Anomaly\AnomalyDetectionService::class)->runForTenant($t->id, RunScope::ofSkus(['Y']), false, true);

        $this->assertSame($before, $shrink->fresh()->only(['lifecycle_state', 'clear_streak', 'cleared_at']),
            'X was not in the scope, so the run says nothing about it');
    }

    public function test_correlation_leaves_what_does_not_fit_its_budget_to_the_next_run(): void
    {
        $t = $this->createTenant(['status' => 'active', 'settings' => ['detection_rules_v2' => true]]);
        foreach (['A', 'B'] as $sku) {
            Anomaly::create(['tenant_id' => $t->id, 'rule_type' => 'sales_drop', 'severity' => 'low', 'sku' => $sku,
                'description' => 'x', 'context' => [], 'detected_at' => now()]);
        }
        $svc = app(\App\Services\Anomaly\InvestigationCorrelationService::class);

        config(['detection.correlation_budget_seconds' => -1]);
        $svc->correlateForTenant($t->id);
        $this->assertSame(2, Anomaly::where('tenant_id', $t->id)->whereNull('investigation_id')->count());

        config(['detection.correlation_budget_seconds' => 1200]);
        $svc->correlateForTenant($t->id);
        $this->assertSame(0, Anomaly::where('tenant_id', $t->id)->whereNull('investigation_id')->count());
    }

    public function test_sku_master_drift_is_answered_in_sql(): void
    {
        $t = $this->createTenant(['status' => 'active', 'settings' => ['detection_rules_v2' => true]]);
        $store = \App\Models\Store::create(['tenant_id' => $t->id, 'name' => 'Mall']);
        \App\Models\Product::create(['tenant_id' => $t->id, 'sku' => 'KNOWN', 'name' => 'Known']);
        foreach (['KNOWN', 'GHOST'] as $sku) {
            DB::table('sales_daily')->insert(['tenant_id' => $t->id, 'store_id' => $store->id, 'sku' => $sku, 'date' => now()->subDays(2)->toDateString(),
                'units_sold' => 3, 'revenue' => 9, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Models\InventoryLevel::create(['tenant_id' => $t->id, 'store_id' => $store->id, 'sku' => 'SHELF', 'on_hand_qty' => 4, 'as_of_date' => now()->toDateString()]);

        app(TenantDetectionRunner::class)->run($t->id, 'full');

        $drift = Anomaly::where('tenant_id', $t->id)->where('rule_type', 'sku_master_drift')->pluck('context', 'sku');
        $this->assertEqualsCanonicalizing(['GHOST', 'SHELF'], $drift->keys()->all());
        $this->assertSame(['inventory'], $drift['SHELF']['found_in']);
    }

    public function test_a_sku_list_is_one_array_parameter_whatever_its_size(): void
    {
        $skus = array_map(fn ($i) => 'S' . $i, range(1, 70000));
        $skus[] = 'odd "quoted" \\ sku';
        DB::table('products')->insert(['tenant_id' => $this->createTenant()->id, 'sku' => 'odd "quoted" \\ sku', 'name' => 'x']);

        $q = RunScope::ofSkus($skus)->constrain(DB::table('products'));

        $this->assertSame(1, $q->count(), '70,001 SKUs — beyond the 65,535-parameter limit of an IN list');
        [$sql, $bind] = RunScope::ofSkus($skus)->sqlClause('sku');
        $this->assertCount(1, $bind);
    }
}
