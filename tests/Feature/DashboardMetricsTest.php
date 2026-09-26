<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Metrics\DashboardMetrics;
use App\Services\Metrics\RecoveryMetrics;
use App\Support\Tenancy\TenantClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP7.1 (audit M6, M10) — one recovery figure everywhere, trends that compare
 * like with like, insights on active anomalies, drill-downs that reproduce
 * the figure and are hidden from users who cannot open them, and a transfer
 * capacity that only counts surplus another store needs.
 */
class DashboardMetricsTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantClock::forget();
        parent::tearDown();
    }

    private function outcome(Tenant $t, float $amount, string $recordedAtUtc): InvestigationOutcome
    {
        $inv = Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_RESOLVED]);

        return InvestigationOutcome::create([
            'investigation_id' => $inv->id, 'tenant_id' => $t->id, 'observed_recovery' => $amount,
            // W10: only measured recovery counts as recovered.
            'measured_recovery' => $amount, 'attribution_status' => InvestigationOutcome::ATTR_ESTIMATED,
            'recorded_at' => Carbon::parse($recordedAtUtc, 'UTC'),
        ]);
    }

    public function test_recovered_mtd_is_one_figure_on_the_tenant_clock(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 10:00', 'UTC'));
        $t = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $other = $this->createTenant(['timezone' => 'Asia/Dubai']);

        $this->outcome($t, 100, '2026-08-31 21:00');   // 1 Sep 01:00 in Dubai → this month
        $this->outcome($t, 40, '2026-09-10 08:00');
        $this->outcome($t, 0, '2026-09-11 08:00');     // nothing recovered → not counted
        $this->outcome($t, 70, '2026-08-31 19:00');    // 31 Aug 23:00 in Dubai → last month
        $this->outcome($t, 25, '2026-08-10 08:00');    // last month, before the same point
        $this->outcome($t, 999, '2026-08-20 08:00');   // last month, after the same point (15th)
        $this->outcome($other, 5000, '2026-09-10 08:00');

        $m = app(RecoveryMetrics::class);
        $this->assertSame(['amount' => 140.0, 'count' => 2], $m->attributedMtd($t->id));
        $this->assertSame(25.0, $m->attributedPrevMtd($t->id)['amount'], 'same stretch of last month, not all of it');

        $dash = app(DashboardMetrics::class)->compute($t->id);
        $this->assertSame(140.0, $dash['kpi']['recovered_mtd']);
        $this->assertSame(25.0, $dash['prev']['recovered_mtd']);

        $this->actingAsTenantAdmin($t);
        // Cards show the currency sign (⃃ for AED), not the ISO code.
        $figure = \App\Support\Money::displayCompact(140.0, \App\Support\Money::normalize($t->currency));
        $this->get(\App\Filament\Pages\FinancialBreakdown::getUrl(['metric' => 'recovered_mtd', 'tenant' => $t]))
            ->assertOk()->assertSee($figure, false);   // the drill-down shows the card's figure
        $this->get(Dashboard::getUrl(['tenant' => $t]))
            ->assertOk()->assertSee($figure, false);   // card and operations pulse
    }

    public function test_the_same_point_last_month_never_overflows(): void
    {
        $this->travelTo(Carbon::parse('2026-03-31 08:00', 'Asia/Dubai'));
        $t = $this->createTenant(['timezone' => 'Asia/Dubai']);

        [$from, $to] = TenantClock::samePeriodLastMonth($t->id);

        $this->assertSame('2026-02-01 00:00', $from->copy()->setTimezone('Asia/Dubai')->format('Y-m-d H:i'));
        $this->assertSame('2026-02-28 08:00', $to->copy()->setTimezone('Asia/Dubai')->format('Y-m-d H:i'), 'not 3 March');
    }

    public function test_trends_compare_new_work_this_week_with_last_week(): void
    {
        $t = $this->createTenant();
        foreach (range(1, 3) as $i) {
            Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN,
                'priority' => 'high', 'revenue_at_risk' => 100, 'opened_at' => now()->subDays(10)]);
        }
        Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN,
            'priority' => 'low', 'revenue_at_risk' => 50, 'opened_at' => now()->subDays(2)]);

        $m = app(DashboardMetrics::class)->compute($t->id);

        $this->assertSame(4, $m['kpi']['open']);
        $this->assertSame([1, 3], $m['flow']['open'], 'opened this week vs the week before');
        $this->assertSame([50.0, 300.0], $m['flow']['risk']);
        $this->assertSame([0, 3], $m['flow']['high']);

        $trend = DashboardMetrics::trend(...[...$m['flow']['open'], false]);
        $this->assertSame(['pct' => 66.7, 'dir' => 'down', 'good' => true], $trend, 'fewer new investigations is good news');
        $this->assertFalse(DashboardMetrics::trend(10, 5, false)['good'], 'more new risk is bad news');
        $this->assertTrue(DashboardMetrics::trend(10, 5, true)['good'], 'more recovery is good news');
    }

    public function test_drivers_and_insights_count_active_anomalies_only(): void
    {
        $t = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'Marina']);
        $mk = fn (string $rule, array $extra = []) => Anomaly::create(array_merge([
            'tenant_id' => $t->id, 'rule_type' => $rule, 'severity' => 'high', 'sku' => 'S' . uniqid(),
            'description' => 'x', 'detected_at' => now()->subDay(), 'store_id' => $store->id, 'ai_is_recurring' => true,
        ], $extra));
        $mk('stockout_risk');
        $mk('stockout_risk');
        foreach (range(1, 3) as $i) {
            $mk('overstock', ['lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED, 'resolved_at' => now()]);
        }
        $mk('overstock', ['dismissed_at' => now()]);

        $m = app(DashboardMetrics::class)->compute($t->id);

        $this->assertSame([['rule_type' => 'stockout_risk', 'cnt' => 2]], $m['drivers']);
        $this->assertSame(['rule_type' => 'stockout_risk', 'cnt' => 2], $m['insights']['recurring']);
        $this->assertSame(['rule_type' => 'stockout_risk', 'cnt' => 2], $m['insights']['month_top']);
        $this->assertSame(['id' => $store->id, 'name' => 'Marina', 'cnt' => 2], $m['insights']['store']);
    }

    public function test_transfer_capacity_counts_only_surplus_another_store_needs(): void
    {
        $t = $this->createTenant();
        [$s1, $s2, $s3] = collect(['One', 'Two', 'Three'])->map(fn ($n) => Store::create(['tenant_id' => $t->id, 'name' => $n]))->all();
        $row = fn (string $sku, Store $s, float $onHand, float $rop, float $upTo) => DB::table('sku_replenishment')->insert([
            'tenant_id' => $t->id, 'sku' => $sku, 'store_id' => $s->id, 'on_hand' => $onHand, 'reorder_point' => $rop,
            'order_up_to' => $upTo, 'daily_rate' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row('A', $s1, 50, 10, 20);   // 30 over order-up-to
        $row('A', $s2, 2, 10, 20);    // below reorder point, needs 18
        $row('B', $s1, 60, 10, 20);   // 40 over, but no store is short of B
        $row('C', $s3, 1, 10, 20);    // short of C, but nobody holds spare C

        $this->assertSame(['units' => 18, 'skus' => 1, 'donors' => 1, 'receivers' => 1],
            app(DashboardMetrics::class)->transferCapacity($t->id));
    }

    public function test_drill_down_links_carry_their_filter_and_are_hidden_without_access(): void
    {
        $t = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'Marina']);
        Anomaly::create(['tenant_id' => $t->id, 'rule_type' => 'stockout_risk', 'severity' => 'high', 'sku' => 'A',
            'description' => 'x', 'detected_at' => now()->subDay(), 'store_id' => $store->id]);
        $inv = Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN, 'priority' => 'high']);

        $this->actingAsTenantAdmin($t);
        $html = urldecode(html_entity_decode($this->get(Dashboard::getUrl(['tenant' => $t]))->assertOk()->getContent()));
        $this->assertStringContainsString('filters[rule_type][value]=stockout_risk', $html, 'the driver opens its rule');
        $this->assertStringContainsString('status=open&store=' . $store->id, $html, 'the store alert opens its store');
        $this->assertStringContainsString('metric=revenue_at_risk', $html);

        $restricted = $this->createUser($t);
        $restricted->forceFill(['visible_screens' => ['action_center']])->save();
        $this->actingAs($restricted);
        DashboardMetrics::forget($t->id);
        $html = html_entity_decode($this->get(Dashboard::getUrl(['tenant' => $t]))->assertOk()->getContent());

        $this->assertStringNotContainsString('financial-breakdown', $html);
        $this->assertStringNotContainsString('/investigations/' . $inv->id, $html);
        $this->assertStringNotContainsString('rule_type', $html);
        $this->assertStringContainsString('tab=overdue', $html, 'the screen they may open stays linked');
    }

    public function test_figures_are_cached_per_tenant_for_a_minute(): void
    {
        $t = $this->createTenant();
        $svc = app(DashboardMetrics::class);
        $this->assertSame(0, $svc->forTenant($t->id)['kpi']['open']);

        Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN]);
        $this->assertSame(0, $svc->forTenant($t->id)['kpi']['open'], 'served from cache');

        $this->travel(DashboardMetrics::TTL + 1)->seconds();
        $this->assertSame(1, $svc->forTenant($t->id)['kpi']['open']);
    }
}
