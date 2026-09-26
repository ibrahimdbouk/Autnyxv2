<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Services\Detection\TenantDetectionRunner;
use App\Services\Inventory\InventoryCurrentService;
use App\Services\Ops\PlatformHealthService;
use App\Support\Testing\SyntheticRetailer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * W13 — detection writes its findings in bulk and correlation links them
 * set-based; Ops is told when a hot table is due for partitioning.
 */
class ScaleWritesTest extends TestCase
{
    public function test_detection_and_correlation_write_in_bulk_with_the_same_lifecycle(): void
    {
        $t = $this->createTenant(['status' => 'active', 'settings' => ['detection_rules_v2' => true]]);
        SyntheticRetailer::seed($t->id, stores: 3, skus: 40, days: 60, signalEvery: 6);
        app(InventoryCurrentService::class)->rebuild($t->id);
        foreach (['sku:profile', 'baselines:compute'] as $cmd) {
            $this->artisan($cmd, ['--tenant' => $t->id]);
        }
        $this->freezeTime();
        $runner = app(TenantDetectionRunner::class);

        DB::enableQueryLog();
        $runner->runFull($t->id);
        app(\App\Services\Anomaly\InvestigationCorrelationService::class)->correlateForTenant($t->id);
        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $found = Anomaly::where('tenant_id', $t->id)->count();
        $rules = Anomaly::where('tenant_id', $t->id)->distinct()->count('rule_type');
        $this->assertGreaterThan(20, $found);
        $inserts = $log->filter(fn ($q) => stripos($q, 'insert into anomalies') !== false || stripos($q, 'insert into "anomalies"') !== false)->count();
        $this->assertLessThanOrEqual($rules + 2, $inserts, "{$found} findings in at most one insert per rule, not one each");
        $this->assertSame(0, Anomaly::where('tenant_id', $t->id)->whereNull('first_seen_at')->count());
        $this->assertSame(0, Anomaly::where('tenant_id', $t->id)->whereNull('identity_key')->count());

        // Correlation: linked set-based, not one UPDATE per finding.
        $single = $log->filter(fn ($q) => str_starts_with($q, 'update "anomalies" set "investigation_id"'))->count();
        $this->assertSame(0, $single);
        $this->assertGreaterThan(0, Investigation::where('tenant_id', $t->id)->count());
        $this->assertSame(0, Anomaly::where('tenant_id', $t->id)->active()->whereNull('investigation_id')->count(), 'every live finding is linked');

        // A second run an hour later: the same subjects persist (touched ids came back from the bulk insert).
        $this->travel(1)->hours();
        DB::enableQueryLog();
        $runner->runFull($t->id);
        $second = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertSame($found, Anomaly::where('tenant_id', $t->id)->count(), 'no duplicate episodes');
        $states = Anomaly::where('tenant_id', $t->id)->pluck('lifecycle_state')->unique()->values()->all();
        $this->assertSame([], array_values(array_diff($states, ['open', 'persisting'])), 'nothing drifts toward recovery: every subject was touched');
        $this->travel(1)->hours();
        $runner->runFull($t->id);
        $this->assertContains('persisting', Anomaly::where('tenant_id', $t->id)->pluck('lifecycle_state')->unique()->all());
        $this->assertSame(0, $second->filter(fn ($q) => str_starts_with($q, 'update "anomalies" set "severity"'))->count(), 'no per-finding updates');
    }

    public function test_ops_is_told_when_a_hot_table_is_due_for_partitioning(): void
    {
        $t = $this->createTenant();
        $rows = [];
        for ($i = 0; $i < 300; $i++) {
            $rows[] = ['tenant_id' => $t->id, 'store_id' => null, 'sku' => 'S' . $i, 'date' => '2026-09-01', 'units_sold' => 1, 'revenue' => 1,
                'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);
        DB::statement('ANALYZE sales_daily');

        $r = collect(app(PlatformHealthService::class)->partitionReadiness(200))->keyBy('table');
        $this->assertSame('partition', $r['sales_daily']['level']);
        $this->assertSame('watch', collect(app(PlatformHealthService::class)->partitionReadiness(360))->keyBy('table')['sales_daily']['level']);
        $this->assertSame([], app(PlatformHealthService::class)->partitionReadiness(100_000_000));

        config(['autnyx.partition_rows' => 200]);
        $this->artisan('system:health-check')->expectsOutputToContain('sales_daily holds')->assertSuccessful();
    }
}
