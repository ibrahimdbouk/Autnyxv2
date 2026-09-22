<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Services\Investigation\DeepInvestigationService;
use Tests\TestCase;

/**
 * Deep Investigation — the optional read-only drill-down layer.
 *
 * Guarantees: it re-projects governed data only (never invents), reuses the
 * deterministic confidence vocabulary verbatim, keeps estimate and measured
 * impact separate, and returns honest empty-states when a source is absent.
 * See claude/deep-investigation.md.
 */
class DeepInvestigationTest extends TestCase
{
    private function anomaly(Investigation $inv, string $rule, string $sku, float $impact): Anomaly
    {
        return Anomaly::create([
            'tenant_id'        => $inv->tenant_id,
            'investigation_id' => $inv->id,
            'rule_type'        => $rule,
            'severity'         => 'high',
            'sku'              => $sku,
            'store_id'         => null,
            'description'      => 'x',
            'detected_at'      => now()->subDays(3),
            'context'          => ['revenue_impact' => $impact, 'supplier' => 'ACME'],
        ]);
    }

    public function test_cause_map_links_a_real_causal_pair_and_marks_a_root(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        // supplier_fill_rate → stockout_risk on the SAME sku is a real CausalGraph edge.
        $this->anomaly($inv, 'supplier_fill_rate', 'SKU-1', 800);
        $this->anomaly($inv, 'stockout_risk', 'SKU-1', 1200);

        $deep = app(DeepInvestigationService::class)->build($inv->fresh());
        $cm = $deep['cause_map'];

        $this->assertTrue($cm['available']);
        $this->assertCount(2, $cm['nodes']);
        $this->assertNotEmpty($cm['edges'], 'the supplier_fill_rate → stockout_risk edge must hold on the same SKU');
        // Confidence vocabulary is reused verbatim — never a fabricated term.
        $this->assertContains($cm['structure'], ['likely', 'verified', 'correlated', 'single']);
        $this->assertTrue(collect($cm['nodes'])->contains('is_root', true), 'a deterministic root must be marked');

        // Fishbone geometry is produced server-side and never empty when signals exist.
        $this->assertArrayHasKey('fishbone', $cm);
        $this->assertNotEmpty($cm['fishbone']['bones']);
        $this->assertNotEmpty($cm['fishbone']['signals']);
        $this->assertNotEmpty($cm['fishbone']['viewbox']);
        $this->assertNotEmpty($cm['head']['label'], 'the fish head names the downstream effect');

        // Every node carries a click-through detail sourced from governed rows only.
        $node = collect($cm['nodes'])->firstWhere('is_root', true);
        $this->assertArrayHasKey('detail', $node);
        $this->assertContains($node['category'], ['supply', 'demand', 'inventory', 'price', 'data', 'other']);
        $this->assertArrayHasKey('evidence', $node['detail']);
    }

    public function test_unrelated_signals_are_shown_as_correlated_not_causal(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        // Two signals that share no CausalGraph edge → co-occurrence only.
        $this->anomaly($inv, 'stockout_risk', 'SKU-1', 500);
        $this->anomaly($inv, 'return_rate_spike', 'SKU-9', 400);

        $cm = app(DeepInvestigationService::class)->build($inv->fresh())['cause_map'];

        $this->assertTrue($cm['available']);
        $this->assertEmpty($cm['edges']);
        $this->assertSame('correlated', $cm['structure']);
    }

    public function test_evidence_and_impact_project_governed_rows_only(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $a = $this->anomaly($inv, 'stockout_risk', 'SKU-1', 1200);

        InvestigationEvidence::create([
            'investigation_id' => $inv->id,
            'anomaly_id'       => $a->id,
            'evidence_type'    => InvestigationEvidence::TYPE_SNAPSHOT,
            'source'           => 'inventory_levels',
            'label'            => 'Current on-hand quantity',
            'value_numeric'    => 3,
            'unit'             => 'units',
            'direction'        => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength'         => InvestigationEvidence::STRENGTH_STRONG,
            'observed_at'      => now(),
        ]);

        $deep = app(DeepInvestigationService::class)->build($inv->fresh());

        $ev = $deep['evidence'];
        $this->assertTrue($ev['available']);
        $this->assertSame(1, $ev['count']);
        $this->assertSame('3 units', $ev['items'][0]['value']);

        $imp = $deep['impact'];
        $this->assertTrue($imp['available']);
        $this->assertSame(1200.0, $imp['total_at_risk']);
        // Estimate is never presented as realised recovery.
        $this->assertNull($imp['measured'], 'no outcome recorded → measured recovery must be null, not fabricated');

        $cf = $deep['confidence'];
        $this->assertTrue($cf['available']);
        $this->assertSame(1, $cf['backing']['supports']);
    }

    public function test_empty_investigation_returns_honest_unavailable_states(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $deep = app(DeepInvestigationService::class)->build($inv->fresh());

        $this->assertFalse($deep['cause_map']['available']);
        $this->assertNotEmpty($deep['cause_map']['empty_reason']);
        $this->assertFalse($deep['evidence']['available']);
        $this->assertFalse($deep['impact']['available']);
        // Slice 2 modules also degrade honestly.
        $this->assertFalse($deep['what_changed']['available']);
        $this->assertFalse($deep['why_rec']['available']);
    }

    public function test_what_changed_surfaces_series_and_shifts_from_evidence(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $a = $this->anomaly($inv, 'sales_drop', 'SKU-1', 900);

        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_DATA_POINT,
            'source' => 'sales_transactions', 'label' => 'Daily sales last 14 days',
            'value_json' => ['2026-09-01' => 12, '2026-09-02' => 9, '2026-09-03' => 3, '2026-09-04' => 2],
            'unit' => 'units/day', 'direction' => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength' => InvestigationEvidence::STRENGTH_STRONG, 'observed_at' => now(),
        ]);
        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_CALCULATION,
            'source' => 'sku_baselines', 'label' => 'Z-score of most recent day vs baseline',
            'value_numeric' => -2.1, 'unit' => 'σ', 'direction' => InvestigationEvidence::DIRECTION_CONTRADICTS,
            'strength' => InvestigationEvidence::STRENGTH_STRONG, 'observed_at' => now(),
        ]);

        $wc = app(DeepInvestigationService::class)->build($inv->fresh())['what_changed'];

        $this->assertTrue($wc['available']);
        $this->assertGreaterThanOrEqual(4, count($wc['series']));
        $this->assertNotEmpty($wc['markers']);
        $this->assertSame(12.0, $wc['series_max']);
    }

    public function test_why_recommendation_is_honest_when_no_target_exists(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $this->anomaly($inv, 'stockout_risk', 'SKU-1', 1200); // no SkuReplenishment target, no actions

        $wr = app(DeepInvestigationService::class)->build($inv->fresh())['why_rec'];

        $this->assertFalse($wr['available'], 'with no derived target and no action there is nothing to prescribe — say so, do not invent');
        $this->assertNotEmpty($wr['empty_reason']);
    }

    public function test_what_if_projects_from_governed_inputs_and_labels_simulated(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $a = $this->anomaly($inv, 'stockout_risk', 'SKU-1', 1200);

        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_SNAPSHOT,
            'source' => 'inventory_levels', 'label' => 'Current on-hand quantity',
            'value_numeric' => 6, 'unit' => 'units', 'direction' => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength' => InvestigationEvidence::STRENGTH_STRONG, 'observed_at' => now(),
        ]);
        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_CALCULATION,
            'source' => 'inventory_levels + sales_transactions', 'label' => 'Days of cover at current sales rate',
            'value_numeric' => 2, 'unit' => 'days', 'direction' => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength' => InvestigationEvidence::STRENGTH_STRONG, 'observed_at' => now(),
        ]);
        // A lead time is required for the "act now" path to close the gap; without
        // it the simulator (correctly) does not assume any protection.
        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_STAT,
            'source' => 'purchase_orders', 'label' => 'Average supplier lead time (recent POs)',
            'value_numeric' => 5, 'unit' => 'days', 'direction' => InvestigationEvidence::DIRECTION_NEUTRAL,
            'strength' => InvestigationEvidence::STRENGTH_MODERATE, 'observed_at' => now(),
        ]);

        $wi = app(DeepInvestigationService::class)->build($inv->fresh())['what_if'];

        $this->assertTrue($wi['available']);
        $this->assertTrue($wi['simulated'], 'the simulator output must always carry the simulated flag');
        $this->assertArrayHasKey($wi['default_horizon'], $wi['scenarios']);
        // avg daily = on_hand(6)/cover(2) = 3; over 14 days, 12 stockout days → 36 units at risk.
        $this->assertSame(36, $wi['scenarios'][14]['no_action']['lost_units']);
        $this->assertGreaterThan(0, $wi['scenarios'][30]['protected_units'], 'a longer horizon protects more by acting');
        // No sibling surplus on file → the transfer alternative is silent, not invented.
        $this->assertNull($wi['transfer'], 'with no releasable surplus anywhere, no transfer alternative is shown');
    }

    public function test_what_if_surfaces_a_transfer_alternative_from_a_surplus_sibling(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $a = $this->anomaly($inv, 'stockout_risk', 'SKU-1', 1200);

        // Governed inputs: on-hand 6, 2 days of cover (⇒ 3 units/day), 5-day PO lead.
        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_SNAPSHOT,
            'source' => 'inventory_levels', 'label' => 'Current on-hand quantity',
            'value_numeric' => 6, 'unit' => 'units', 'direction' => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength' => InvestigationEvidence::STRENGTH_STRONG, 'observed_at' => now(),
        ]);
        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_CALCULATION,
            'source' => 'inventory_levels + sales_transactions', 'label' => 'Days of cover at current sales rate',
            'value_numeric' => 2, 'unit' => 'days', 'direction' => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength' => InvestigationEvidence::STRENGTH_STRONG, 'observed_at' => now(),
        ]);
        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_STAT,
            'source' => 'purchase_orders', 'label' => 'Average supplier lead time (recent POs)',
            'value_numeric' => 5, 'unit' => 'days', 'direction' => InvestigationEvidence::DIRECTION_NEUTRAL,
            'strength' => InvestigationEvidence::STRENGTH_MODERATE, 'observed_at' => now(),
        ]);

        // A sibling store holds this SKU well above its OWN target — releasable
        // surplus of 20 units (on-hand 30 vs order-up-to 10). No lead_time_days set,
        // so the PO lead still resolves from the evidence above.
        \App\Models\SkuReplenishment::create([
            'tenant_id' => $tenant->id, 'sku' => 'SKU-1', 'store_id' => 42,
            'on_hand' => 30, 'order_up_to' => 10, 'source' => 'computed',
        ]);

        $wi = app(DeepInvestigationService::class)->build($inv->fresh())['what_if'];

        $this->assertTrue($wi['available']);
        $tr = $wi['transfer'];
        $this->assertNotNull($tr, 'a genuine surplus sibling must surface a transfer alternative');
        $this->assertTrue($tr['available']);
        $this->assertEquals(42, $tr['best_store']);
        $this->assertSame(20, $tr['best_surplus'], 'releasable surplus = on-hand 30 − target 10');
        // Pre-PO gap = (lead 5 − cover 2) × 3/day = 9 units; surplus (20) covers it fully.
        $this->assertSame(9, $tr['protected_units']);
        $this->assertTrue($tr['fully_covered']);
        // It recommends only — it never claims to move stock itself.
        $this->assertStringContainsStringIgnoringCase('recommends only', $tr['assumption']);
    }

    public function test_what_if_populates_from_replenishment_data_when_evidence_lacks_a_rate(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $this->anomaly($inv, 'stockout_risk', 'SKU-1', 1200); // no what-if evidence at all

        // The governed replenishment row is the ONLY input source here — this is the
        // real-prod case where a stockout signal carries no explicit on-hand/rate
        // evidence but the nightly replenishment model has the figures.
        \App\Models\SkuReplenishment::create([
            'tenant_id' => $tenant->id, 'sku' => 'SKU-1', 'store_id' => 7,
            'on_hand' => 6, 'daily_rate' => 3, 'order_up_to' => 40,
            'lead_time_days' => 5, 'unit_cost' => 2.5, 'source' => 'computed',
        ]);

        $wi = app(DeepInvestigationService::class)->build($inv->fresh())['what_if'];

        $this->assertTrue($wi['available'], 'the simulator must populate from governed replenishment data when evidence has no rate');
        $this->assertTrue($wi['has_revenue'], 'unit_cost from the replenishment row enables the revenue view');
        $this->assertSame(3.0, $wi['inputs']['avg_daily'], 'sales rate falls back to the model daily_rate');
        $this->assertSame(5, $wi['inputs']['lead_time']);
        // on_hand 6 / rate 3 = 2 days cover; over 14 days → 12 stockout days × 3 = 36.
        $this->assertSame(36, $wi['scenarios'][14]['no_action']['lost_units']);
    }

    public function test_similar_incidents_match_resolved_siblings(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $this->anomaly($inv, 'stockout_risk', 'SKU-1', 1200);

        $sibling = Investigation::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => Investigation::STATUS_RESOLVED,
            'resolved_at' => now()->subDays(10),
            'title' => 'Prior stockout on SKU-9',
        ]);
        $this->anomaly($sibling, 'stockout_risk', 'SKU-9', 500);

        $si = app(DeepInvestigationService::class)->build($inv->fresh())['similar'];

        $this->assertTrue($si['available']);
        $this->assertNotEmpty($si['items']);
        $this->assertStringContainsStringIgnoringCase('signal', $si['items'][0]['match']);
    }
}
