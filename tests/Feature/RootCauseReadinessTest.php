<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Product;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Import\ImportProcessorService;
use App\Support\Testing\SyntheticRetailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W10 — Root Cause readiness: detection cost, promotions, recovery honesty,
 * onboarding.
 */
class RootCauseReadinessTest extends TestCase
{
    public function test_the_v1_engine_looks_up_open_anomalies_once_per_rule_not_once_per_flag(): void
    {
        $t = $this->createTenant();
        SyntheticRetailer::seed($t->id, 4, 60, 40, 0.9, 8);

        $lookups = 0;
        DB::listen(function ($q) use (&$lookups) {
            if (str_contains($q->sql, 'from "anomalies" where "tenant_id" = ? and "rule_type" = ? and "sku" = ?')) {
                $lookups++;
            }
        });
        $svc = app(AnomalyDetectionService::class);
        $svc->runForTenant($t->id);
        $flags = array_sum($svc->emittedByRule());

        $this->assertGreaterThan(20, $flags, 'the planted signals are flagged');
        $this->assertSame(0, $lookups, 'no per-flag lookups');

        // A second run updates the same SKU-keyed rows instead of adding new ones.
        // (v1's SKU-less rules add a row a night — audit C10, fixed in v2 — so they are left out.)
        $before = DB::table('anomalies')->where('tenant_id', $t->id)->whereNotNull('sku')->count();
        app(AnomalyDetectionService::class)->runForTenant($t->id);
        $this->assertSame($before, DB::table('anomalies')->where('tenant_id', $t->id)->whereNotNull('sku')->count());
    }

    // ── Precision benchmark ──────────────────────────────────────────────────

    public function test_the_benchmark_scores_detection_against_planted_problems_and_cleans_up(): void
    {
        $tenantsBefore = DB::table('tenants')->count();
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        $code = \Illuminate\Support\Facades\Artisan::call('detection:benchmark', ['--stores' => 12, '--skus' => 160, '--days' => 60, '--signal-every' => 16, '--json' => true], $buffer);
        $this->assertSame(0, $code);
        $out = $buffer->fetch();
        $r = json_decode(substr($out, strpos($out, '{')), true);

        $this->assertCount(4, $r['families']);
        $collapse = $r['families'][0];
        $this->assertGreaterThan(0, $collapse['planted']);
        $this->assertGreaterThan(0, $collapse['found'], 'planted collapses are found');
        $stockout = $r['families'][3];
        $this->assertSame($stockout['planted'], $stockout['found'], 'every planted stock-out is found');
        $this->assertSame(0, $r['promotions']['flagged'], 'a planted promotion is never flagged as a demand anomaly');
        $this->assertSame($tenantsBefore, DB::table('tenants')->count(), 'the synthetic tenant is erased');
    }

    /** Found by the benchmark: with under ~70 days of history every SKU was profiled "intermittent" and demand rules were gated off. */
    public function test_a_young_tenant_s_daily_sellers_are_not_profiled_as_intermittent(): void
    {
        $t = $this->createTenant();
        $store = DB::table('stores')->insertGetId(['tenant_id' => $t->id, 'name' => 'S', 'code' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        $end = Carbon::yesterday();
        $this->dailySeries($t->id, $store, 'DAILY', $end, fn ($ago) => 20 + ($ago % 3));   // 35 days, sells every day
        app(\App\Services\Anomaly\SkuProfilerService::class)->profileForTenant($t->id, 90);

        $p = DB::table('sku_profiles')->where('tenant_id', $t->id)->where('sku', 'DAILY')->where('store_id', $store)->first();
        $this->assertSame('smooth', $p->segment);
        $this->assertEqualsWithDelta(1.0, (float) $p->adi, 0.05, 'ADI over the history there is, not the 90-day window');
    }

    // ── Recovery honesty ─────────────────────────────────────────────────────

    public function test_recovery_typed_in_by_hand_is_a_claim_and_only_measured_recovery_counts(): void
    {
        $t = $this->createTenant();
        $inv = \App\Models\Investigation::factory()->create(['tenant_id' => $t->id, 'status' => 'resolved', 'revenue_at_risk' => 1000]);
        $inv2 = \App\Models\Investigation::factory()->create(['tenant_id' => $t->id, 'status' => 'resolved', 'revenue_at_risk' => 500]);
        $this->actingAs($this->createUser($t, admin: true));

        $claim = app(\App\Services\OutcomeService::class)->record($inv, ['outcome_type' => 'resolved', 'observed_recovery' => 700]);
        $this->assertSame(\App\Models\InvestigationOutcome::ATTR_CLAIMED, $claim->attribution_status);
        $this->assertNull($claim->measured_recovery);

        // A measured one (as the measurement writes it).
        \App\Models\InvestigationOutcome::create(['investigation_id' => $inv2->id, 'tenant_id' => $t->id, 'outcome_type' => 'resolved',
            'observed_recovery' => 300, 'measured_recovery' => 300, 'attribution_status' => 'estimated', 'recorded_at' => now()]);

        $m = app(\App\Services\Metrics\RecoveryMetrics::class);
        $this->assertSame(300.0, $m->attributedMtd($t->id)['amount'], 'the claim is not counted as recovered');
        $this->assertSame(['amount' => 700.0, 'count' => 1], $m->claimed($t->id));
        $s = app(\App\Services\OutcomeService::class)->tenantSummary($t->id);
        $this->assertSame([300.0, 700.0], [$s['total_recovered'], $s['total_claimed']]);

        // Re-entering a figure on a measured outcome keeps it measured.
        $again = app(\App\Services\OutcomeService::class)->record($inv2->fresh(), ['observed_recovery' => 400, 'measured_recovery' => 9999]);
        $this->assertSame('estimated', $again->attribution_status);
        $this->assertEquals(300, (float) $again->measured_recovery, 'measured recovery is never written from a form');
    }

    public function test_the_outcome_audit_finds_computed_claims_on_problems_still_detected(): void
    {
        $t = $this->createTenant();
        $store = DB::table('stores')->insertGetId(['tenant_id' => $t->id, 'name' => 'S', 'code' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1000, 2000, 4000] as $i => $risk) {
            $inv = \App\Models\Investigation::factory()->create(['tenant_id' => $t->id, 'status' => 'resolved', 'revenue_at_risk' => $risk,
                'primary_sku' => "SKU{$i}", 'primary_store_id' => $store]);
            \App\Models\InvestigationOutcome::create(['investigation_id' => $inv->id, 'tenant_id' => $t->id, 'outcome_type' => 'resolved',
                'revenue_at_risk' => $risk, 'observed_recovery' => $risk * 0.7, 'attribution_status' => 'not_attempted', 'recorded_at' => now()]);
        }
        \App\Models\Anomaly::factory()->create(['tenant_id' => $t->id, 'sku' => 'SKU0', 'store_id' => $store, 'rule_type' => 'phantom_inventory']);

        $this->artisan('outcomes:audit', ['--tenant' => $t->id])->assertSuccessful()
            ->expectsOutputToContain('STILL DETECTED: phantom_inventory')
            ->expectsOutputToContain('exactly 70% of the value at risk');
        $this->assertSame(0, \App\Models\InvestigationOutcome::where('attribution_status', 'claimed')->count(), 'dry run');

        $this->artisan('outcomes:audit', ['--tenant' => $t->id, '--apply' => true])->assertSuccessful()->expectsOutputToContain('3 marked claimed');
        $this->assertSame(0.0, app(\App\Services\Metrics\RecoveryMetrics::class)->attributedMtd($t->id)['amount']);
    }

    // ── Onboarding ───────────────────────────────────────────────────────────

    public function test_the_setup_checklist_is_read_from_the_data_and_offers_templates(): void
    {
        $t = $this->createTenant();
        $svc = app(\App\Services\Onboarding\OnboardingService::class);
        $this->assertSame(['done' => 0, 'total' => 11, 'required_left' => 6, 'pct' => 0], $svc->progress($t->id));   // W11: + waste (optional)

        $store = DB::table('stores')->insertGetId(['tenant_id' => $t->id, 'name' => 'S', 'code' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        Product::create(['tenant_id' => $t->id, 'sku' => 'A', 'name' => 'A', 'unit_cost' => 1, 'selling_price' => 2]);
        $this->dailySeries($t->id, $store, 'A', Carbon::yesterday(), fn () => 5);   // 35 days
        $steps = collect($svc->steps($t->id))->keyBy('key');
        $this->assertTrue($steps['stores']['done']);
        $this->assertTrue($steps['sales']['done']);
        $this->assertStringContainsString('90+ days sharpens it', $steps['sales']['detail']);
        $this->assertFalse($steps['inventory']['done']);
        $this->assertSame(3, $svc->progress($t->id)['required_left']);

        $this->actingAsTenantAdmin($t);
        $this->get(\App\Filament\Pages\GettingStarted::getUrl(['tenant' => $t]))->assertOk()
            ->assertSee('3 required step(s) left')->assertSee('Load current stock')->assertSee("mountAction('runDetection'", false);
        $csv = $this->get('/import-templates/promotions.csv')->assertOk()->getContent();
        $this->assertSame("promotion_ref,sku,start_date,end_date,location,promotion_name,mechanic,discount_pct,promo_price\n", $csv);
        $this->get('/import-templates/users.csv')->assertNotFound();

        $viewer = $this->createUser($t);
        $this->actingAs($viewer)->get(\App\Filament\Pages\GettingStarted::getUrl(['tenant' => $t]))->assertForbidden();
    }

    // ── Promotions ───────────────────────────────────────────────────────────

    private function runImport(Import $import): Import
    {
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 50);

        return $import->fresh();
    }

    private function csvImport(int $tenantId, string $type, string $csv, array $map): Import
    {
        $path = 'imports/pending/p-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create(['tenant_id' => $tenantId, 'original_filename' => 'promos.csv', 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_UPLOADED, 'total_rows' => substr_count(trim($csv), "\n"),
            'source' => Import::SOURCE_UPLOAD, 'feed_key' => Import::feedKeyFor(Import::SOURCE_UPLOAD, $type)]);
        foreach ($map as $h => $field) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $field, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        return $import;
    }

    public function test_a_promotion_calendar_imports_and_a_resent_calendar_updates_rather_than_doubles(): void
    {
        Storage::fake('local');
        Queue::fake();
        $t = $this->createTenant();
        DB::table('stores')->insert(['tenant_id' => $t->id, 'name' => 'Marina', 'code' => 'MAR', 'created_at' => now(), 'updated_at' => now()]);
        $map = ['Promo' => 'promotion_ref', 'Item' => 'sku', 'From' => 'start_date', 'To' => 'end_date', 'Store' => 'location', 'Deal' => 'mechanic', 'Off' => 'discount_pct'];
        $csv = "Promo,Item,From,To,Store,Deal,Off\nRAMADAN1,A1,2026-03-01,2026-03-14,,price cut,20%\nRAMADAN1,B2,2026-03-01,2026-03-14,MAR,multibuy,\nBAD,C3,2026-03-10,2026-03-01,,,\n";

        $i = $this->runImport($this->csvImport($t->id, Import::TYPE_PROMOTIONS, $csv, $map));
        $this->assertSame(2, DB::table('promotions')->where('tenant_id', $t->id)->count());
        $this->assertSame(1, $i->failed_rows, 'a promotion that ends before it starts is refused');
        $a1 = DB::table('promotions')->where('sku', 'A1')->first();
        $this->assertNull($a1->store_id, 'blank store = every store');
        $this->assertEquals(20, (float) $a1->discount_pct);
        $this->assertNotNull(DB::table('promotions')->where('sku', 'B2')->value('store_id'));

        // Re-sent with a new end date: updated in place.
        $csv2 = "Promo,Item,From,To,Store,Deal,Off\nRAMADAN1,A1,2026-03-01,2026-03-21,,price cut,25\n";
        $this->runImport($this->csvImport($t->id, Import::TYPE_PROMOTIONS, $csv2, $map));
        $this->assertSame(2, DB::table('promotions')->where('tenant_id', $t->id)->count());
        $this->assertSame('2026-03-21', substr((string) DB::table('promotions')->where('sku', 'A1')->value('ends_on'), 0, 10));
    }

    private function dailySeries(int $tenantId, int $storeId, string $sku, Carbon $end, callable $unitsForDaysAgo): void
    {
        $rows = [];
        for ($ago = 34; $ago >= 0; $ago--) {
            $u = $unitsForDaysAgo($ago);
            $rows[] = ['tenant_id' => $tenantId, 'store_id' => $storeId, 'sku' => $sku, 'date' => $end->copy()->subDays($ago)->toDateString(),
                'units_sold' => $u, 'revenue' => $u * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);
    }

    public function test_a_swing_a_promotion_explains_is_not_flagged_and_the_same_swing_without_one_is(): void
    {
        $t = $this->createTenant(['settings' => ['detection_rules_v2' => true]]);
        $store = DB::table('stores')->insertGetId(['tenant_id' => $t->id, 'name' => 'Marina', 'code' => 'MAR', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['SPIKE_PROMO', 'SPIKE_REAL', 'DROP_PROMO', 'DROP_REAL'] as $sku) {
            Product::create(['tenant_id' => $t->id, 'sku' => $sku, 'name' => $sku, 'unit_cost' => 6, 'selling_price' => 10]);
        }
        $end = Carbon::parse('2026-09-20');
        // Spikes: 5× in the last 7 days.
        foreach (['SPIKE_PROMO', 'SPIKE_REAL'] as $sku) {
            $this->dailySeries($t->id, $store, $sku, $end, fn ($ago) => $ago < 7 ? 250 : 50);
        }
        // Drops: a busy week inside the 28-day baseline, then back to normal.
        foreach (['DROP_PROMO', 'DROP_REAL'] as $sku) {
            $this->dailySeries($t->id, $store, $sku, $end, fn ($ago) => ($ago >= 14 && $ago < 21) ? 300 : 50);
        }
        DB::table('promotions')->insert([
            ['tenant_id' => $t->id, 'promotion_ref' => 'P1', 'sku' => 'SPIKE_PROMO', 'store_id' => null, 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-20', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $t->id, 'promotion_ref' => 'P2', 'sku' => 'DROP_PROMO', 'store_id' => $store, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-06', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $svc = app(AnomalyDetectionService::class);
        $svc->runForTenant($t->id);

        $flagged = DB::table('anomalies')->where('tenant_id', $t->id)->whereIn('rule_type', ['sales_spike', 'sales_drop'])
            ->pluck('sku')->unique()->sort()->values()->all();
        $this->assertSame(['DROP_REAL', 'SPIKE_REAL'], $flagged);
        $this->assertSame(1, $svc->promoSuppressedByRule()['sales_spike'] ?? 0);
        $this->assertSame(1, $svc->promoSuppressedByRule()['sales_drop'] ?? 0);

        // Switched off: the promoted swings are flagged like any other.
        config(['detection.promo_suppression' => false]);
        app(AnomalyDetectionService::class)->runForTenant($t->id);
        $this->assertCount(4, DB::table('anomalies')->where('tenant_id', $t->id)->whereIn('rule_type', ['sales_spike', 'sales_drop'])->pluck('sku')->unique());
    }

    public function test_promotions_on_sales_lines_count_when_they_are_a_real_share(): void
    {
        $t = $this->createTenant();
        $store = DB::table('stores')->insertGetId(['tenant_id' => $t->id, 'name' => 'Marina', 'code' => 'MAR', 'created_at' => now(), 'updated_at' => now()]);
        $line = fn ($sku, $date, $qty, $ref) => ['tenant_id' => $t->id, 'store_id' => $store, 'sku' => $sku, 'date' => $date, 'quantity' => $qty,
            'unit_price' => 10, 'total_amount' => $qty * 10, 'promotion_ref' => $ref, 'created_at' => now(), 'updated_at' => now()];
        DB::table('sales_transactions')->insert([
            $line('BIG', '2026-09-10', 80, 'DEAL'), $line('BIG', '2026-09-10', 20, null),
            $line('COUPON', '2026-09-10', 1, 'C-99'), $line('COUPON', '2026-09-10', 99, null),
        ]);
        foreach (['BIG' => 100, 'COUPON' => 100] as $sku => $u) {
            DB::table('sales_daily')->insert(['tenant_id' => $t->id, 'store_id' => $store, 'sku' => $sku, 'date' => '2026-09-10',
                'units_sold' => $u, 'revenue' => $u * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $cal = \App\Services\Detection\PromotionCalendar::load($t->id, '2026-09-01', '2026-09-30');
        $this->assertNotNull($cal->overlapping('BIG', $store, '2026-09-08', '2026-09-12'));
        $this->assertNull($cal->overlapping('COUPON', $store, '2026-09-08', '2026-09-12'), 'one coupon line is not a promotion');
        $this->assertNull($cal->overlapping('BIG', $store, '2026-09-11', '2026-09-20'));
    }
}
