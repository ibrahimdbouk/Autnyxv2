<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\EvidenceCollectorService;
use App\Services\Anomaly\InvestigationCorrelationService;
use App\Services\Anomaly\RootCauseAnalysisService;
use App\Services\InvestigationNarratorService;
use App\Services\Quality\QualityMetricsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WP4.5 (audit H22, H23, M17, M18, M20, M21) — correlation by subject, the
 * deterministic cause handed to the narrator, tidy-tail resurfacing, refreshed
 * evidence, absence rules in aggregate mode.
 */
class CorrelationAndCauseTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['settings' => ['detection_rules_v2' => true]]);
    }

    private function anomaly(string $rule, ?string $sku, ?int $store, array $context = [], array $attrs = []): Anomaly
    {
        return Anomaly::create(array_merge([
            'tenant_id' => $this->tenant->id, 'rule_type' => $rule, 'severity' => 'medium', 'sku' => $sku, 'store_id' => $store,
            'description' => $rule, 'context' => $context + ['revenue_impact' => 1000], 'detected_at' => now(),
        ], $attrs));
    }

    private function correlate(): void
    {
        app(InvestigationCorrelationService::class)->correlateForTenant($this->tenant->id);
    }

    public function test_sku_less_anomalies_open_an_investigation_per_subject_not_one_catch_all(): void
    {
        $this->anomaly('supplier_lead_time_drift', null, null, ['subject' => 'supplier:acme']);
        $this->anomaly('supplier_lead_time_drift', null, null, ['subject' => 'supplier:beta']);
        $this->anomaly('import_frequency_gap', null, null);

        $this->correlate();

        $this->assertSame(3, Investigation::where('tenant_id', $this->tenant->id)->count());
        $this->assertEqualsCanonicalizing(['supplier:acme', 'supplier:beta', 'rule:import_frequency_gap'],
            Investigation::pluck('subject_key')->all());
    }

    public function test_an_active_investigation_keeps_absorbing_its_subject_however_long_ago_it_opened(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S1']);
        $this->anomaly('sales_drop', 'MILK', null);
        $this->correlate();
        $inv = Investigation::sole();
        $inv->update(['opened_at' => now()->subDays(20), 'status' => Investigation::STATUS_IN_PROGRESS]);

        $this->anomaly('stockout_risk', 'MILK', $store->id);   // the same loss, seen at a store
        $this->correlate();

        $this->assertSame(1, Investigation::count(), 'joined the active chain-level MILK investigation');
        $this->assertSame(2, (int) $inv->fresh()->anomaly_count);
    }

    public function test_the_cause_links_a_store_signal_to_a_chain_level_one_and_ignores_dismissed_signals(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S1']);
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_sku' => 'MILK']);
        $this->anomaly('stockout_risk', 'MILK', $store->id, [], ['investigation_id' => $inv->id]);
        $this->anomaly('sales_drop', 'MILK', null, [], ['investigation_id' => $inv->id]);
        $this->anomaly('supplier_fill_rate', 'MILK', null, [], ['investigation_id' => $inv->id, 'dismissed_at' => now()]);

        $r = app(RootCauseAnalysisService::class)->record($inv);

        $this->assertSame('likely', $r['tier'], 'stockout → drop linked on the SKU although the drop is chain-wide');
        $this->assertSame('stockout_risk', $r['root_rule'], 'the dismissed supplier signal is not part of the cause');
        $this->assertSame('probable', $inv->fresh()->ai_confidence);
        $this->assertSame('stockout_risk', $inv->fresh()->root_cause_rule);
    }

    public function test_the_narrator_is_given_the_cause_and_cannot_set_the_confidence(): void
    {
        config(['services.anthropic.key' => 'test']);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S1']);
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_sku' => 'MILK']);
        $this->anomaly('stockout_risk', 'MILK', $store->id, [], ['investigation_id' => $inv->id]);
        $this->anomaly('sales_drop', 'MILK', $store->id, [], ['investigation_id' => $inv->id]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'headline' => 'h', 'summary' => 's', 'root_cause' => 'A competitor opened next door', 'confidence' => 'established',
        ])]]])]);

        $result = app(InvestigationNarratorService::class)->narrate($inv, force: true);

        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], 'ROOT CAUSE — decided by Autnyx')
            && str_contains($r['messages'][0]['content'], 'Stockout'));
        $this->assertSame('probable', $result->ai_confidence, 'from the deterministic tier, not the model');
    }

    public function test_an_incident_joining_an_auto_snoozed_trend_item_brings_it_back(): void
    {
        $this->anomaly('demand_erosion', 'TEA', null, ['revenue_impact' => 300]);
        $this->correlate();
        $inv = Investigation::sole();
        $inv->update(['snoozed_until' => now()->addDays(30), 'snooze_reason' => 'auto_low_value_tail', 'snoozed_at' => now()]);

        $this->anomaly('stockout_risk', 'TEA', null);
        $this->correlate();

        $this->assertNull($inv->fresh()->snoozed_until);
    }

    public function test_the_tidy_run_resurfaces_a_snoozed_item_that_worsened_past_the_floor(): void
    {
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'status' => Investigation::STATUS_OPEN,
            'revenue_at_risk' => 5000, 'snoozed_until' => now()->addDays(20), 'snooze_reason' => 'auto_low_value_tail']);

        $this->artisan('queue:tidy-tail', ['--tenant' => $this->tenant->id])->assertSuccessful();

        $this->assertNull($inv->fresh()->snoozed_until);
    }

    public function test_evidence_is_refreshed_not_frozen(): void
    {
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_sku' => 'MILK']);
        $a = $this->anomaly('plan_variance', 'MILK', null, ['forecast_units' => 100, 'actual_units' => 40, 'deviation_pct' => -60, 'window_days' => 14],
            ['investigation_id' => $inv->id]);
        $collector = app(EvidenceCollectorService::class);
        $collector->collectForInvestigation($inv);

        $a->update(['context' => ['forecast_units' => 100, 'actual_units' => 20, 'deviation_pct' => -80, 'window_days' => 14]]);
        $collector->collectForInvestigation($inv);

        $e = InvestigationEvidence::where('investigation_id', $inv->id)->where('label', 'Units sold (last 14 days)')->sole();
        $this->assertEqualsWithDelta(20, $e->value_numeric, 0.001);
    }

    public function test_the_established_cause_rate_counts_the_deterministic_tier(): void
    {
        Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'root_cause_tier' => 'corroborated', 'ai_confidence' => 'suspected']);
        Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'root_cause_tier' => 'correlated', 'ai_confidence' => 'established', 'ai_generated_at' => now()]);

        $this->assertEqualsWithDelta(50.0, app(QualityMetricsService::class)->rates($this->tenant->id)['established_cause_rate'], 0.01);
    }

    public function test_a_sku_that_stopped_selling_is_caught_by_the_aggregate_run(): void
    {
        \App\Models\Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'GONE', 'name' => 'Gone', 'selling_price' => 100]);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S1']);
        $rows = [];
        foreach (range(34, 7) as $ago) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'GONE', 'date' => Carbon::today()->subDays($ago)->toDateString(),
                'units_sold' => 10, 'revenue' => 1000, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (range(6, 0) as $ago) {   // the rest of the business keeps selling
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'OTHER', 'date' => Carbon::today()->subDays($ago)->toDateString(),
                'units_sold' => 5, 'revenue' => 50, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id, null, aggregateOnly: true);

        $this->assertTrue(Anomaly::where('rule_type', 'sales_drop')->where('sku', 'GONE')->exists());
    }
}
