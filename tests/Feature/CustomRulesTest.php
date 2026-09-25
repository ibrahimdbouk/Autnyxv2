<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CustomMetricResource;
use App\Filament\Resources\CustomRuleResource;
use App\Filament\Resources\CustomRuleResource\Pages\CreateCustomRule;
use App\Models\Anomaly;
use App\Models\CustomMetricDefinition;
use App\Models\CustomRuleDefinition;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Platform\Extensibility\CustomRuleEngine;
use App\Platform\Extensibility\Expression\Evaluator;
use App\Platform\Extensibility\Expression\Formula;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\AnomalyDismissal;
use App\Support\Detection\ValueModel;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * W10 (WP10.6) — tenant admins write their own rules and KPIs as formulas;
 * rules run with detection and raise anomalies like built-in rules, KPIs show
 * on the dashboard. Nothing typed is ever executed as code.
 */
class CustomRulesTest extends TestCase
{
    private Tenant $tenant;
    private Store $marina;
    private Store $mall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-20 10:00'));
        $this->tenant = $this->createTenant(['settings' => ['detection_rules_v2' => true], 'currency' => 'AED']);
        $this->marina = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina']);
        $this->mall = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Mall']);
        foreach (['FAST' => 10, 'SLOW' => 10] as $sku => $cost) {
            Product::create(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => $sku, 'unit_cost' => $cost, 'selling_price' => 25]);
        }
        // FAST sells 10 a day in both stores; SLOW 1 a day. Latest sales date: 18 Sep.
        $rows = [];
        foreach ([$this->marina, $this->mall] as $s) {
            for ($d = 0; $d < 40; $d++) {
                $date = Carbon::parse('2026-09-18')->subDays($d)->toDateString();
                $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $s->id, 'sku' => 'FAST', 'date' => $date, 'units_sold' => 10, 'revenue' => 250, 'transaction_count' => 5];
                $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $s->id, 'sku' => 'SLOW', 'date' => $date, 'units_sold' => 1, 'revenue' => 25, 'transaction_count' => 1];
            }
        }
        DB::table('sales_daily')->insert($rows);
        // Marina holds 12 FAST (1.2 days of cover); Mall holds 200 (20 days).
        foreach ([[$this->marina, 'FAST', 12], [$this->mall, 'FAST', 200], [$this->marina, 'SLOW', 50], [$this->mall, 'SLOW', 50]] as [$s, $sku, $qty]) {
            DB::table('inventory_current')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $s->id, 'sku' => $sku,
                'as_of_date' => '2026-09-18', 'on_hand_qty' => $qty, 'unit_cost' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function rule(array $attrs = []): CustomRuleDefinition
    {
        return CustomRuleDefinition::create(CustomRuleResource::prepare(array_merge([
            'tenant_id' => $this->tenant->id, 'label' => 'Fast seller about to run out',
            'formula' => 'units_7d >= 20 and days_of_cover < 3', 'severity' => 'critical',
            'impact_formula' => 'avg_daily_units_28d * selling_price * 7', 'value_type' => ValueModel::LOST_REVENUE, 'active' => true,
        ], $attrs)));
    }

    private function detect(): void
    {
        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);
    }

    private function hits(): \Illuminate\Support\Collection
    {
        return Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'custom_rule')->get();
    }

    public function test_formulas_compile_with_precedence_and_friendly_errors(): void
    {
        $e = new Evaluator();
        $ast = Formula::compile('2 + 3 * 4 > 13 and not (1 = 2)')['ast'];
        $this->assertTrue($e->evaluate($ast));
        $this->assertSame(-4, $e->evaluate(Formula::compile('-(2 + 2)')['ast']));
        $this->assertEqualsWithDelta(2.5, $e->evaluate(Formula::compile('units / 4', ['units'])['ast'], ['units' => 10]), 1e-9);
        $this->assertSame(['units_7d', 'on_hand'], Formula::compile('Units_7d > On_Hand')['variables'], 'names are case-insensitive');

        foreach ([
            'units_7d >'             => 'ends too early',
            '(units_7d > 3'          => 'not closed',
            'units_7d > 3 )'         => 'Unexpected',
            'units_7d ; drop table'  => 'Unexpected character',
            'system(1)'              => 'Unexpected',       // no function calls
            'price > 1'              => 'Unknown variable',
            'units_7d'               => 'yes/no test',
        ] as $bad => $why) {
            try {
                CustomRuleEngine::compileCondition($bad);
                $this->fail("accepted: {$bad}");
            } catch (InvalidArgumentException $ex) {
                $this->assertStringContainsString($why, $ex->getMessage(), $bad);
            }
        }
    }

    public function test_preview_finds_the_positions_the_formula_describes(): void
    {
        $p = app(CustomRuleEngine::class)->preview($this->tenant->id, 'units_7d >= 20 and days_of_cover < 3', 'avg_daily_units_28d * selling_price * 7');

        $this->assertSame(1, $p['count'], 'only FAST at Marina: 70 sold this week, 1.2 days of cover');
        $this->assertSame(['store_id' => $this->marina->id, 'sku' => 'FAST'], array_intersect_key($p['samples'][0], ['store_id' => 0, 'sku' => 0]));
        $this->assertEqualsWithDelta(1750.0, $p['impact_total'], 0.01, '10 a day × 25 × 7 days');
        $this->assertEquals(['units_7d' => 70.0, 'days_of_cover' => 1.2], $p['samples'][0]['inputs']);
    }

    public function test_detection_raises_one_anomaly_per_hit_that_reconciles_and_is_superseded_when_the_rule_changes(): void
    {
        $rule = $this->rule();
        $this->rule(['label' => 'Selling below cost', 'formula' => 'selling_price < unit_cost', 'impact_formula' => null, 'severity' => 'info']);

        $this->detect();
        $hits = $this->hits();
        $this->assertCount(1, $hits, 'nothing sells below cost');
        $a = $hits->first();
        $this->assertSame([$this->marina->id, 'FAST', 'high'], [$a->store_id, $a->sku, $a->severity]);
        $this->assertSame('Fast seller about to run out', $a->getRuleLabel());
        $this->assertSame('custom:fast_seller_about_to_run_out', $a->context['subject']);
        $this->assertSame(ValueModel::LOST_REVENUE, $a->value_type);
        $this->assertEqualsWithDelta(1750, $a->value_at_open, 0.01);
        $this->assertStringContainsString('SKU FAST at Marina (units_7d 70, days_of_cover 1.2)', $a->description);

        $this->travel(1)->days();
        $this->detect();
        $this->assertCount(1, $this->hits(), 'the same hit next night updates, not duplicates');
        $this->assertSame('2026-09-21', $a->fresh()->last_seen_at->toDateString(), 'seen again');

        // A formula that no longer compiles skips the rule: its anomaly is left alone.
        DB::table('custom_rule_definitions')->where('id', $rule->id)->update(['formula' => 'units_7d >']);
        $this->travel(1)->days();
        $this->detect();
        $a->refresh();
        $this->assertSame([Anomaly::LIFECYCLE_OPEN, '2026-09-21'], [$a->lifecycle_state, $a->last_seen_at->toDateString()], 'not cleared by a broken formula, and not seen either');

        // Rewriting the rule sets its old findings aside as superseded (nothing was fixed).
        $rule->refresh()->update(CustomRuleResource::prepare(['formula' => 'units_7d >= 20 and days_of_cover < 30'] + $rule->only(['label', 'key', 'tenant_id'])));
        $this->assertSame(AnomalyDismissal::REASON_SUPERSEDED, $a->fresh()->dismiss_reason);
        $this->detect();
        $this->assertSame(2, $this->hits()->whereNull('dismissed_at')->count(), 'the rewritten rule matches FAST at both stores');
    }

    public function test_the_master_switch_turns_every_custom_rule_off(): void
    {
        $this->rule();
        \App\Models\AnomalySetting::seedForTenant($this->tenant->id);
        \App\Models\AnomalySetting::where('tenant_id', $this->tenant->id)->where('rule_type', 'custom_rule')->update(['enabled' => false]);

        $this->detect();

        $this->assertCount(0, $this->hits());
    }

    public function test_admins_write_rules_on_a_screen_that_validates_the_formula(): void
    {
        $this->actingAsTenantAdmin($this->tenant);
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($this->tenant);
        $panel->boot();

        Livewire::test(CreateCustomRule::class)
            ->fillForm(['label' => 'Low cover', 'formula' => 'days_of_cover < banana', 'severity' => 'warning'])
            ->call('create')
            ->assertHasFormErrors(['formula']);

        Livewire::test(CreateCustomRule::class)
            ->fillForm(['label' => 'Low cover', 'formula' => 'days_of_cover < 3', 'severity' => 'warning'])
            ->call('create')
            ->assertHasNoFormErrors();

        $r = CustomRuleDefinition::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame('low_cover', $r->key);
        $this->assertSame(['type' => 'op', 'op' => '<', 'args' => [['type' => 'var', 'name' => 'days_of_cover'], ['type' => 'const', 'value' => 3]]], $r->condition);

        $this->get(CustomRuleResource::getUrl('index', tenant: $this->tenant))->assertOk()->assertSee('Low cover');

        $analyst = $this->createUser($this->tenant);
        $this->actingAs($analyst);
        $this->get(CustomRuleResource::getUrl('index', tenant: $this->tenant))->assertForbidden();
        $this->get(CustomMetricResource::getUrl('index', tenant: $this->tenant))->assertForbidden();
    }

    public function test_custom_kpis_compute_on_tenant_figures_and_show_on_the_dashboard(): void
    {
        $m = CustomMetricDefinition::create(CustomMetricResource::prepare([
            'tenant_id' => $this->tenant->id, 'label' => 'Stock cover, weeks', 'unit' => 'ratio',
            'formula' => 'stock_value / (revenue_28d / 4)', 'active' => true,
        ]));
        // stock 312 units × 10 = 3,120; revenue 28d = 2 stores × 28 × (250 + 25) = 15,400
        $this->assertEqualsWithDelta(3120 / (15400 / 4), app(CustomRuleEngine::class)->metricValue($m), 1e-6);
        $this->assertSame('stock_cover_weeks', $m->key);

        $this->actingAsTenantAdmin($this->tenant);
        $this->get(Dashboard::getUrl(['tenant' => $this->tenant]))->assertOk()
            ->assertSee('Your KPIs')->assertSee('Stock cover, weeks')->assertSee('0.81');
    }
}
