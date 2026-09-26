<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\RootCauseAnalysisService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * W13 — a cause has to come before its effect in the data.
 */
class TemporalRootCauseTest extends TestCase
{
    private Tenant $tenant;
    private Store $store;
    private Investigation $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-26 06:00'));
        $this->tenant = $this->createTenant();
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina', 'code' => 'MAR']);
        $this->inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_sku' => 'W1']);

        // Stock: fine on 2 Sep, low from 3 Sep on.
        foreach (['2026-09-02' => 20, '2026-09-03' => 5, '2026-09-10' => 0, '2026-09-20' => 0] as $d => $q) {
            DB::table('inventory_levels')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'sku' => 'W1',
                'on_hand_qty' => $q, 'reorder_point' => 10, 'as_of_date' => $d, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Sales: 10 a day to 4 Sep, 1 a day from 5 Sep.
        for ($d = Carbon::parse('2026-08-20'); $d->lte(Carbon::parse('2026-09-23')); $d->addDay()) {
            DB::table('sales_daily')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'sku' => 'W1',
                'date' => $d->toDateString(), 'units_sold' => $d->lt(Carbon::parse('2026-09-05')) ? 10 : 1, 'revenue' => 0,
                'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->anom('stockout_risk', []);
        $this->anom('sales_drop', ['window' => ['2026-09-10', '2026-09-23'], 'days' => 14, 'expected_daily' => 10, 'recent_daily' => 1]);
    }

    private function anom(string $rule, array $ctx): Anomaly
    {
        return Anomaly::create(['tenant_id' => $this->tenant->id, 'investigation_id' => $this->inv->id, 'rule_type' => $rule,
            'severity' => 'high', 'sku' => 'W1', 'store_id' => $this->store->id, 'description' => $rule,
            'context' => $ctx + ['revenue_impact' => 1000], 'detected_at' => now(), 'first_seen_at' => now()]);
    }

    public function test_a_chain_in_order_in_the_data_is_confirmed_and_dated(): void
    {
        $this->anom('po_overdue', ['po_number' => 'P1', 'expected_date' => '2026-09-01']);

        $r = app(RootCauseAnalysisService::class)->analyze($this->inv);

        $this->assertSame('po_overdue', $r['root_rule']);
        $this->assertSame('corroborated', $r['tier']);
        $this->assertSame(['2026-09-01', '2026-09-03', '2026-09-05'], array_column($r['chain'], 'onset'));
        $this->assertSame([null, 'in_order', 'in_order'], array_column($r['chain'], 'timing'));
        $this->assertSame(['in_order' => 2, 'unverified' => 0, 'reversed' => 0], $r['timing']);
        $this->assertStringContainsString('The timing checks out', $r['explanation']);
    }

    public function test_a_supposed_cause_that_started_after_the_effect_is_left_out(): void
    {
        // The PO was only due on 15 Sep — the shelf had been low since 3 Sep.
        $this->anom('po_late_receipt', ['po_number' => 'P2', 'expected_date' => '2026-09-15', 'received_date' => '2026-09-24']);

        $r = app(RootCauseAnalysisService::class)->analyze($this->inv);

        $this->assertSame('stockout_risk', $r['root_rule'], 'the late PO is not the cause');
        $this->assertSame('likely', $r['tier']);
        $this->assertSame(1, $r['timing']['reversed']);
        $this->assertSame('2026-09-15', $r['reversed'][0]['cause_from']);
        $this->assertStringContainsString('started after the effect', $r['explanation']);
    }

    public function test_without_dates_in_the_data_a_link_stays_but_is_not_confirmed(): void
    {
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_sku' => 'C1']);
        foreach (['cost_spike', 'margin_erosion'] as $rule) {
            Anomaly::create(['tenant_id' => $this->tenant->id, 'investigation_id' => $inv->id, 'rule_type' => $rule, 'severity' => 'high',
                'sku' => 'C1', 'description' => $rule, 'context' => ['revenue_impact' => 100], 'detected_at' => now()]);
        }

        $r = app(RootCauseAnalysisService::class)->analyze($inv);

        $this->assertSame('cost_spike', $r['root_rule']);
        $this->assertSame(['in_order' => 0, 'unverified' => 1, 'reversed' => 0], $r['timing']);
        $this->assertStringContainsString('timing is not confirmed', $r['explanation']);
    }

    public function test_the_order_check_allows_a_day_for_files_landing_on_different_nights(): void
    {
        $d = fn (?string $e, ?string $l) => ['earliest' => $e, 'latest' => $l];
        $this->assertSame('in_order', RootCauseAnalysisService::order($d('2026-09-02', '2026-09-02'), $d('2026-09-01', '2026-09-01')));
        $this->assertSame('reversed', RootCauseAnalysisService::order($d('2026-09-03', '2026-09-03'), $d('2026-09-01', '2026-09-01')));
        $this->assertSame('unverified', RootCauseAnalysisService::order($d(null, '2026-09-10'), $d(null, '2026-09-01')));
    }
}
