<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Tenant;
use App\Services\Anomaly\ThresholdTuningService;
use Tests\TestCase;

/**
 * B7: outcome-driven learning loop recommends (never auto-applies) a tighter
 * floor for a rule that has been noisy on a decent sample, using impact
 * separation to keep the genuine hits.
 */
class ThresholdTuningTest extends TestCase
{
    public function test_recommends_raising_floor_for_a_noisy_rule(): void
    {
        $tenant = $this->createTenant();
        AnomalySetting::seedForTenant($tenant->id); // stockout_risk floor default = 100

        // 6 false positives (small impact, but above the current 100 floor) and
        // 4 genuine hits (large impact).
        foreach ([150, 180, 200, 220, 250, 300] as $imp) $this->outcome($tenant, 'stockout_risk', $imp, true);
        foreach ([2000, 2500, 3000, 3500] as $imp)       $this->outcome($tenant, 'stockout_risk', $imp, false);

        $suggestions = app(ThresholdTuningService::class)->suggestionsForTenant($tenant->id);

        $this->assertArrayHasKey('stockout_risk', $suggestions);
        $s = $suggestions['stockout_risk'];
        $this->assertSame('min_revenue', $s['key']);
        $this->assertSame(10, $s['sample']);
        $this->assertEqualsWithDelta(0.6, $s['fp_rate'], 0.001);
        $this->assertGreaterThan(100.0, $s['suggested']);   // raised above current floor
        $this->assertLessThan(2000.0, $s['suggested']);     // but below the cheapest genuine hit
    }

    public function test_quiet_rule_gets_no_suggestion(): void
    {
        $tenant = $this->createTenant();
        AnomalySetting::seedForTenant($tenant->id);

        // Mostly genuine hits → low FP rate → nothing to tune.
        foreach ([500, 600, 700, 800, 900, 1000, 1100, 1200] as $imp) $this->outcome($tenant, 'stockout_risk', $imp, false);
        $this->outcome($tenant, 'stockout_risk', 120, true);

        $this->assertArrayNotHasKey('stockout_risk', app(ThresholdTuningService::class)->suggestionsForTenant($tenant->id));
    }

    private function outcome(Tenant $tenant, string $rule, float $impact, bool $fp): void
    {
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id, 'status' => 'resolved']);
        Anomaly::create([
            'tenant_id' => $tenant->id, 'investigation_id' => $inv->id, 'rule_type' => $rule,
            'severity' => 'high', 'sku' => 'SKU-' . $inv->id, 'description' => 'x',
            'context' => ['revenue_impact' => $impact], 'detected_at' => now(),
        ]);
        InvestigationOutcome::create([
            'investigation_id' => $inv->id, 'tenant_id' => $tenant->id,
            'was_false_positive' => $fp, 'outcome_type' => $fp ? 'false_positive' : 'resolved',
            'revenue_at_risk' => $impact, 'recorded_at' => now(),
        ]);
    }
}
