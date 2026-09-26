<?php

namespace Tests\Feature;

use App\Mail\ValueReportMail;
use App\Models\Action;
use App\Models\Anomaly;
use App\Models\CycleCount;
use App\Models\Import;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\AnomalyDismissal;
use App\Services\Anomaly\AnomalyFeedback;
use App\Services\Counts\CycleCountService;
use App\Services\Import\ImportProcessorService;
use App\Services\Outcome\OutcomeMeasurementService;
use App\Services\OutcomeService;
use App\Services\Quality\QualityMetricsService;
use App\Services\Reporting\ReportDataService;
use App\Services\Reporting\ValueReportService;
use App\Services\Suppliers\SupplierScorecardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * W11 — proof of value (automatic measurement from the fix, the value report),
 * one-click feedback (app, Action Center, digest e-mail), cycle-count lists,
 * fresh & expiry (waste import, expiry and waste rules), supplier scorecard.
 */
class ProofOfValueTest extends TestCase
{
    private Tenant $tenant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 10:00'));
        $this->tenant = $this->createTenant(['settings' => ['detection_rules_v2' => true], 'currency' => 'AED']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina', 'code' => 'MAR']);
    }

    private function daily(string $sku, int $daysAgo, float $units, float $price = 10, ?int $store = null): array
    {
        return ['tenant_id' => $this->tenant->id, 'store_id' => $store ?? $this->store->id, 'sku' => $sku,
            'date' => Carbon::parse('2026-09-19')->subDays($daysAgo)->toDateString(), 'units_sold' => $units, 'revenue' => $units * $price, 'transaction_count' => 1];
    }

    private function anomaly(string $rule, string $sku, array $ctx = [], array $attrs = []): Anomaly
    {
        return Anomaly::create(array_merge(['tenant_id' => $this->tenant->id, 'rule_type' => $rule, 'severity' => 'high', 'sku' => $sku,
            'store_id' => $this->store->id, 'description' => "{$rule} {$sku}", 'context' => $ctx, 'detected_at' => now()->subDays(20)], $attrs));
    }

    // ── 1. Proof of value ───────────────────────────────────────────────────

    public function test_a_resolved_outcome_starts_the_measurement_and_the_recovery_is_measured_not_typed(): void
    {
        // A: 10 a day, collapses to 2 a day 20 days ago, back to 10 after the fix 10 days ago. B: steady (the market).
        $rows = [];
        for ($d = 0; $d < 60; $d++) {
            $rows[] = $this->daily('A', $d, $d >= 20 ? 10 : ($d >= 10 ? 2 : 10));
            $rows[] = $this->daily('B', $d, 50);
        }
        DB::table('sales_daily')->insert($rows);
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'status' => Investigation::STATUS_RESOLVED,
            'primary_sku' => 'A', 'primary_store_id' => $this->store->id, 'revenue_at_risk' => 560, 'opened_at' => now()->subDays(19), 'resolved_at' => now()->subDays(9)]);
        $this->anomaly('sales_drop', 'A', ['revenue_impact' => 560], ['investigation_id' => $inv->id, 'value_type' => 'lost_revenue']);

        $outcome = app(OutcomeService::class)->record($inv, [
            'outcome_type' => InvestigationOutcome::TYPE_RESOLVED, 'observed_recovery' => 999,
            'recovery_method' => InvestigationOutcome::RECOVERY_SALES_REBOUND,
            'recovery_measured_from' => now()->subDays(10)->toDateString(), 'recovery_measured_to' => now()->toDateString(),
        ]);

        $fix = $inv->actions()->sole();
        $this->assertSame([Action::TYPE_FIX_RECORDED, Action::STATUS_COMPLETED, '2026-09-10'],
            [$fix->action_type, $fix->status, $fix->completed_at->toDateString()], 'the fix itself is the action measured from');
        $this->assertTrue($outcome->fresh()->isClaimOnly(), 'the typed 999 is a claim until measured');

        $this->assertTrue(app(OutcomeMeasurementService::class)->measureInvestigation($inv->fresh()));
        $o = $outcome->fresh();
        $this->assertGreaterThan(0, (float) $o->measured_recovery, 'day-7 checkpoint: sales came back');
        $this->assertLessThan(999, (float) $o->measured_recovery, 'measured, not the typed figure');

        // Value report: found, acted, measured — the typed figure is not "recovered".
        $v = app(ValueReportService::class)->build($this->tenant->id, now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(1, $v['acted']['actions_completed']);
        $this->assertEqualsWithDelta((float) $o->measured_recovery, $v['measured']['revenue'], 0.01);
        $this->assertSame(1, $v['measured']['revenue_count']);
        $this->assertSame($inv->id, $v['wins'][0]['investigation_id']);
    }

    public function test_old_resolved_outcomes_get_a_measurable_fix_by_command(): void
    {
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'status' => Investigation::STATUS_RESOLVED, 'resolved_at' => now()->subDays(5)]);
        InvestigationOutcome::create(['investigation_id' => $inv->id, 'tenant_id' => $this->tenant->id,
            'outcome_type' => InvestigationOutcome::TYPE_RESOLVED, 'observed_recovery' => 100, 'recorded_at' => now()->subDays(5)]);
        $fp = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'status' => Investigation::STATUS_RESOLVED]);
        InvestigationOutcome::create(['investigation_id' => $fp->id, 'tenant_id' => $this->tenant->id,
            'outcome_type' => InvestigationOutcome::TYPE_FALSE_POSITIVE, 'was_false_positive' => true, 'recorded_at' => now()]);

        $this->artisan('outcomes:start-measurement', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->assertSame(0, Action::count(), 'dry run');

        $this->artisan('outcomes:start-measurement', ['--tenant' => $this->tenant->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, $inv->actions()->where('action_type', Action::TYPE_FIX_RECORDED)->count());
        $this->assertSame('2026-09-15', $inv->actions()->first()->completed_at->toDateString(), 'dated when it was resolved');
        $this->assertSame(0, $fp->actions()->count(), 'a false positive is not measured');
    }

    public function test_the_value_report_is_a_report_type_and_goes_out_monthly_once(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-10-01 02:00', 'Asia/Dubai'));
        Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'opened_at' => Carbon::parse('2026-09-12'), 'revenue_at_risk' => 1234]);
        $this->tenant->forceFill(['notification_email' => null])->save();
        $admin = $this->createUser($this->tenant, admin: true);
        $this->createUser($this->tenant);   // not an admin: not sent

        $this->artisan('reports:value-monthly', ['--tenant' => $this->tenant->id])->assertSuccessful();
        Mail::assertQueued(ValueReportMail::class, fn ($m) => $m->hasTo($admin->email) && $m->monthLabel === 'September 2026');
        Mail::assertQueuedCount(1);
        $this->assertSame('2026-09', $this->tenant->fresh()->settings['value_report_sent']);

        $this->artisan('reports:value-monthly', ['--tenant' => $this->tenant->id])->assertSuccessful();
        Mail::assertQueuedCount(1);

        $payload = app(ReportDataService::class)->build('value', $this->tenant->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30 23:59:59'));
        $this->assertSame('Value Delivered', $payload['title']);
        $this->assertSame('AED 1,234.00', collect($payload['summary_sections'][0]['rows'])->firstWhere(0, 'Lost revenue identified')[1]);

        $this->actingAs($admin);
        $this->get(route('reports.download', ['type' => 'value', 'format' => 'pdf']) . '?tenant=' . $this->tenant->id . '&from=2026-09-01&to=2026-09-30')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    // ── 2. Feedback ─────────────────────────────────────────────────────────

    public function test_one_click_feedback_gives_precision_per_rule_and_not_real_dismisses(): void
    {
        $fb = app(AnomalyFeedback::class);
        $a = $this->anomaly('stockout_risk', 'A');
        $b = $this->anomaly('stockout_risk', 'B');
        $c = $this->anomaly('stockout_risk', 'C');
        $d = $this->anomaly('overstock', 'D');

        $fb->record($a, AnomalyFeedback::REAL);
        $fb->record($b, AnomalyFeedback::REAL);
        $fb->record($c, AnomalyFeedback::NOT_REAL);
        app(AnomalyDismissal::class)->dismiss($d, AnomalyDismissal::REASON_FALSE_POSITIVE);

        $this->assertSame(AnomalyDismissal::REASON_FALSE_POSITIVE, $c->fresh()->dismiss_reason);
        $this->assertNotNull($c->fresh()->dismissed_at);
        $this->assertSame(AnomalyFeedback::NOT_REAL, $d->fresh()->feedback, 'a false-positive dismissal is a "not real" answer');

        $p = $fb->precisionByRule($this->tenant->id);
        $this->assertSame(['real' => 2, 'not_real' => 1, 'precision' => 66.7], $p['stockout_risk']);
        $this->assertSame(0.0, $p['overstock']['precision']);

        $rule = collect(app(QualityMetricsService::class)->rulePerformance($this->tenant->id))->firstWhere('rule_type', 'stockout_risk');
        $this->assertSame([66.7, 3], [$rule['precision'], $rule['answered']]);

        // Changing one's mind: "real" after "not real" re-opens it.
        $fb->record($c->fresh(), AnomalyFeedback::REAL);
        $this->assertNull($c->fresh()->dismissed_at);
        $this->assertSame(AnomalyFeedback::REAL, $c->fresh()->feedback);
    }

    public function test_digest_feedback_links_confirm_before_saving_and_are_bound_to_the_recipient(): void
    {
        $a = $this->anomaly('stockout_risk', 'A');
        $user = $this->createUser($this->tenant);
        $url = URL::temporarySignedRoute('feedback.show', now()->addDays(14), ['anomaly' => $a->id, 'verdict' => 'not_real', 'r' => 'user:' . $user->id]);

        $this->get($url)->assertOk()->assertSee('Confirm — not real');
        $this->assertNull($a->fresh()->feedback, 'opening the link (or a mail scanner) changes nothing');

        $this->post($url)->assertOk()->assertSee('Your answer is saved');
        $a->refresh();
        $this->assertSame(['not_real', 'email', $user->id], [$a->feedback, $a->feedback_via, $a->feedback_by]);
        $this->assertNotNull($a->dismissed_at);

        $this->get($url . 'x')->assertForbidden();   // tampered signature
        $other = $this->createTenant();
        $stranger = $this->createUser($other);
        $foreign = URL::temporarySignedRoute('feedback.show', now()->addDays(14), ['anomaly' => $a->id, 'verdict' => 'real', 'r' => 'user:' . $stranger->id]);
        $this->get($foreign)->assertForbidden();
        $this->travel(15)->days();
        $this->get($url)->assertForbidden();   // expired
    }

    public function test_the_digest_carries_feedback_links(): void
    {
        $a = $this->anomaly('stockout_risk', 'A');
        $mail = new \App\Mail\AnomalyDigestMail($this->tenant, new \Illuminate\Database\Eloquent\Collection([$a]), 1, null, 'tenant:' . $this->tenant->id);
        $html = $mail->render();
        $this->assertStringContainsString('/feedback/' . $a->id . '/real', html_entity_decode($html));
        $this->assertStringContainsString('/feedback/' . $a->id . '/not_real', html_entity_decode($html));
    }

    // ── 3. Cycle counts ─────────────────────────────────────────────────────

    public function test_count_lists_rank_doubts_per_store_and_a_count_becomes_a_measured_action(): void
    {
        DB::table('inventory_current')->insert([
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'sku' => 'P1', 'as_of_date' => '2026-09-19', 'on_hand_qty' => 40, 'unit_cost' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'sku' => 'P2', 'as_of_date' => '2026-09-19', 'on_hand_qty' => 10, 'unit_cost' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'status' => Investigation::STATUS_OPEN]);
        $p1 = $this->anomaly('phantom_inventory', 'P1', ['inventory_value' => 200], ['investigation_id' => $inv->id]);
        $this->anomaly('negative_inventory', 'P2', ['inventory_value' => 900]);
        $this->anomaly('stockout_risk', 'P3', ['revenue_impact' => 5000]);   // not a stock doubt

        $svc = app(CycleCountService::class);
        $this->assertSame(['added' => 2, 'kept' => 0, 'cancelled' => 0], $svc->generate($this->tenant->id));
        $this->assertSame(['P2', 'P1'], CycleCount::orderBy('rank')->pluck('sku')->all(), 'ranked by the money behind the doubt');

        $count = CycleCount::where('sku', 'P1')->sole();
        $svc->record($count, 12, null, 'app');
        $count->refresh();
        $this->assertSame([CycleCount::STATUS_COUNTED, 40.0, -28.0, -140.0], [$count->status, $count->system_qty, $count->variance_qty, $count->variance_value]);
        $act = $inv->actions()->sole();
        $this->assertSame([Action::TYPE_CYCLE_COUNT, Action::STATUS_COMPLETED], [$act->action_type, $act->status]);

        // Counted recently → not listed again; a doubt that cleared → taken off the list.
        $p1->update(['lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED, 'resolved_at' => now()]);
        Anomaly::where('sku', 'P2')->update(['lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED, 'resolved_at' => now()]);
        $this->assertSame(['added' => 0, 'kept' => 0, 'cancelled' => 1], $svc->generate($this->tenant->id));
    }

    public function test_counts_upload_from_the_downloaded_csv(): void
    {
        DB::table('inventory_current')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'sku' => 'P1', 'as_of_date' => '2026-09-19',
            'on_hand_qty' => 7, 'unit_cost' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->anomaly('inventory_shrinkage', 'P1', ['inventory_value' => 50]);
        $svc = app(CycleCountService::class);
        $svc->generate($this->tenant->id);

        $path = tempnam(sys_get_temp_dir(), 'cc');
        file_put_contents($path, "\xEF\xBB\xBFstore,sku,product,reason,system_qty,counted_qty\nMAR,P1,,Shrink,7,7\nMAR,ZZ,,x,1,3\nMAR,P9,,x,1,\n");
        $r = $svc->importCsv($this->tenant->id, $path);
        @unlink($path);

        $this->assertSame(1, $r['recorded']);
        $this->assertCount(1, $r['skipped'], 'unknown SKU reported; blank count skipped silently');
        $this->assertSame(0.0, CycleCount::where('sku', 'P1')->value('variance_qty'));
    }

    // ── 4. Fresh & expiry ───────────────────────────────────────────────────

    public function test_expiry_risk_sells_batches_first_expiry_first_out(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'MILK', 'name' => 'Milk', 'unit_cost' => 10, 'selling_price' => 14]);
        // Sells 2 a day. On hand: 10 expiring in 3 days, 30 expiring in 10 days.
        DB::table('sales_daily')->insert(array_map(fn ($d) => $this->daily('MILK', $d, 2, 6), range(0, 27)));
        foreach ([['2026-09-22', 10], ['2026-09-29', 30]] as [$exp, $qty]) {
            DB::table('inventory_levels')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'sku' => 'MILK',
                'location' => 'Marina', 'as_of_date' => '2026-09-19', 'on_hand_qty' => $qty, 'expiry_date' => $exp, 'batch_ref' => $exp,
                'unit_cost' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(\App\Services\Inventory\InventoryCurrentService::class)->rebuild($this->tenant->id);

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $a = Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'expiry_risk')->sole();
        // Batch 1: 3 days × 2 = 6 sell, 4 expire. Batch 2: by day 10, 20 sold in total → 14 more; 16 expire. 20 units, AED 200.
        $this->assertEquals(20, $a->context['units_at_risk']);
        $this->assertEquals(200, $a->context['inventory_value']);
        $this->assertSame('2026-09-22', $a->context['first_expiry']);
        $this->assertSame('capital_at_cost', $a->value_type);
    }

    public function test_waste_imports_and_a_rising_waste_rate_is_flagged(): void
    {
        Storage::fake('local');
        Queue::fake();
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'BREAD', 'name' => 'Bread', 'unit_cost' => 3, 'selling_price' => 5, 'department' => 'Fresh']);
        DB::table('sales_daily')->insert(array_map(fn ($d) => $this->daily('BREAD', $d, 10, 5), range(0, 89)));

        // Before: 1 a week. Last 28 days: 4 a day (two lines a day, same reason — they add up).
        $lines = ["Date,Item,Store,Qty,Why,Doc"];
        for ($d = 30; $d < 84; $d += 7) {
            $lines[] = Carbon::parse('2026-09-19')->subDays($d)->toDateString() . ",BREAD,MAR,1,expired,";
        }
        for ($d = 0; $d < 28; $d++) {
            $day = Carbon::parse('2026-09-19')->subDays($d)->toDateString();
            $lines[] = "{$day},BREAD,MAR,-2,Expired,";
            $lines[] = "{$day},BREAD,MAR,2,Expired,";
        }
        $import = $this->csvImport(Import::TYPE_WASTE, implode("\n", $lines) . "\n",
            ['Date' => 'date', 'Item' => 'sku', 'Store' => 'location', 'Qty' => 'quantity', 'Why' => 'reason', 'Doc' => 'waste_ref']);
        $this->runImport($import);
        $this->assertSame(8 + 28, DB::table('waste_events')->where('tenant_id', $this->tenant->id)->count());
        $this->assertEquals(4, DB::table('waste_events')->where('date', '2026-09-19')->value('quantity'), 'lines sharing a key add up; the negative sign is dropped');

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);
        $a = Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'waste_rate')->sole();
        $this->assertEquals(112, $a->context['waste_units']);
        $this->assertEqualsWithDelta(28.6, $a->context['waste_pct'], 0.05);   // 112 / (280 + 112)
        $this->assertEquals(336, $a->context['value_impact']);                // 112 × 3

        $s = app(\App\Services\Fresh\FreshExpiryService::class)->summary($this->tenant->id);
        $this->assertEquals(336, $s['waste']['value']);
        $this->assertSame('Fresh', $s['waste']['by_category'][0]['key']);

        // Re-sent: replaces, never doubles.
        $this->runImport($this->csvImport(Import::TYPE_WASTE, implode("\n", $lines) . "\n",
            ['Date' => 'date', 'Item' => 'sku', 'Store' => 'location', 'Qty' => 'quantity', 'Why' => 'reason', 'Doc' => 'waste_ref']));
        $this->assertEquals(4, DB::table('waste_events')->where('date', '2026-09-19')->value('quantity'));
    }

    // ── 5. Supplier scorecard ───────────────────────────────────────────────

    public function test_the_supplier_scorecard_grades_fill_on_time_and_cost(): void
    {
        $good = DB::table('suppliers')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Good Foods', 'created_at' => now(), 'updated_at' => now()]);
        $bad  = DB::table('suppliers')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Late Co', 'created_at' => now(), 'updated_at' => now()]);
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $order = Carbon::parse('2026-09-15')->subDays(5 * $i);
            $rows[] = ['tenant_id' => $this->tenant->id, 'supplier_id' => $good, 'supplier' => 'Good Foods', 'po_number' => "G{$i}", 'sku' => 'A',
                'qty_ordered' => 100, 'qty_received' => 100, 'unit_cost' => 10, 'order_date' => $order->toDateString(),
                'expected_date' => $order->copy()->addDays(3)->toDateString(), 'received_date' => $order->copy()->addDays(3)->toDateString()];
            $rows[] = ['tenant_id' => $this->tenant->id, 'supplier_id' => $bad, 'supplier' => 'Late Co', 'po_number' => "L{$i}", 'sku' => 'B',
                'qty_ordered' => 100, 'qty_received' => 60, 'unit_cost' => $i < 3 ? 12 : 10, 'order_date' => $order->toDateString(),
                'expected_date' => $order->copy()->addDays(3)->toDateString(), 'received_date' => $order->copy()->addDays($i % 2 ? 9 : 3)->toDateString()];
        }
        DB::table('purchase_orders')->insert(array_map(fn ($r) => $r + ['created_at' => now(), 'updated_at' => now()], $rows));
        $this->anomaly('stockout_risk', 'B', ['revenue_impact' => 700]);

        $card = collect(app(SupplierScorecardService::class)->scorecard($this->tenant->id, 90))->keyBy('name');
        $this->assertSame(['A', 100, 100.0, 100.0], [$card['Good Foods']['grade'], $card['Good Foods']['score'], $card['Good Foods']['fill_rate'], $card['Good Foods']['on_time']]);
        $l = $card['Late Co'];
        $this->assertSame([60.0, 50.0, 'D'], [$l['fill_rate'], $l['on_time'], $l['grade']]);
        // Last 30 days of orders: three at 12 and three at 10 (avg 11) against 10 before → +10%.
        $this->assertEqualsWithDelta(10.0, $l['cost_change'], 0.1);
        $this->assertSame([1, 700.0], [$l['stockouts'], $l['lost_revenue']]);
        $this->assertSame('Late Co', array_key_first($card->all()), 'worst first');

        $detail = app(SupplierScorecardService::class)->detail($this->tenant->id, $bad, 90);
        $this->assertSame(60.0, $detail['skus'][0]['fill_rate']);
    }

    // ── Screens ─────────────────────────────────────────────────────────────

    public function test_the_new_screens_render_and_follow_screen_permissions(): void
    {
        $this->anomaly('phantom_inventory', 'P1', ['inventory_value' => 200]);
        app(CycleCountService::class)->generate($this->tenant->id);
        $this->actingAsTenantAdmin($this->tenant);
        foreach ([\App\Filament\Pages\CountLists::class => 'P1', \App\Filament\Pages\FreshExpiry::class => 'Waste, last 28 days',
                  \App\Filament\Pages\SupplierScorecard::class => 'No purchase orders', \App\Filament\Pages\Reports::class => 'Value Delivered'] as $page => $see) {
            $this->get($page::getUrl(['tenant' => $this->tenant]))->assertOk()->assertSee($see);
        }

        $viewer = $this->createUser($this->tenant);
        $viewer->forceFill(['visible_screens' => ['action_center']])->save();
        $this->actingAs($viewer);
        $this->get(\App\Filament\Pages\CountLists::getUrl(['tenant' => $this->tenant]))->assertForbidden();
        $this->get(\App\Filament\Pages\SupplierScorecard::getUrl(['tenant' => $this->tenant]))->assertForbidden();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

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

    private function csvImport(string $type, string $csv, array $map): Import
    {
        $path = 'imports/pending/w-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create(['tenant_id' => $this->tenant->id, 'original_filename' => 'waste-' . uniqid() . '.csv', 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_UPLOADED, 'total_rows' => substr_count(trim($csv), "\n"),
            'source' => Import::SOURCE_UPLOAD, 'feed_key' => Import::feedKeyFor(Import::SOURCE_UPLOAD, $type)]);
        foreach ($map as $h => $field) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $field, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        return $import;
    }
}
