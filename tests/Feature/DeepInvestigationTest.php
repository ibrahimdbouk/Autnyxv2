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
    }
}
