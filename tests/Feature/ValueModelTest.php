<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\OutcomeMeasurement;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Investigation\DeterministicRevenueAtRisk;
use App\Services\Outcome\OutcomeMeasurementService;
use App\Services\Recovery\AnomalyRecoveryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP4.4 (audit H21) — revenue at risk is live lost revenue counted once;
 * capital is reported beside it; recovery uses the same basis and is measured
 * against a counterfactual, once per checkpoint.
 */
class ValueModelTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['settings' => ['detection_rules_v2' => true]]);
    }

    private function member(Investigation $inv, string $rule, ?string $sku, ?int $store, array $context, array $attrs = []): Anomaly
    {
        return Anomaly::create(array_merge([
            'tenant_id' => $this->tenant->id, 'investigation_id' => $inv->id, 'rule_type' => $rule, 'severity' => 'medium',
            'sku' => $sku, 'store_id' => $store, 'description' => 'x', 'context' => $context, 'detected_at' => now(),
        ], $attrs));
    }

    public function test_revenue_at_risk_counts_live_lost_revenue_once_and_keeps_capital_apart(): void
    {
        $s1 = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S1']);
        $s2 = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S2']);
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->member($inv, 'stockout_risk', 'A', $s1->id, ['revenue_impact' => 1000]);
        $this->member($inv, 'sales_drop', 'A', null, ['revenue_impact' => 1500]);          // same loss, chain view
        $this->member($inv, 'overstock', 'B', $s2->id, ['inventory_value' => 5000, 'revenue_impact' => 5000]);
        $this->member($inv, 'stockout_risk', 'C', $s1->id, ['revenue_impact' => 800], ['lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED]);
        $this->member($inv, 'sales_drop', 'D', null, ['revenue_impact' => 900], ['dismissed_at' => now()]);
        $this->member($inv, 'sales_spike', 'E', null, ['revenue_impact' => 700]);          // upside

        app(DeterministicRevenueAtRisk::class)->sync($inv);

        $inv->refresh();
        $this->assertEqualsWithDelta(1500, $inv->revenue_at_risk, 0.01, 'the larger of the overlapping views of A');
        $this->assertEqualsWithDelta(5000, $inv->capital_at_risk, 0.01);
    }

    public function test_observed_recovery_is_lost_revenue_only_and_counted_once_per_subject(): void
    {
        $base = ['tenant_id' => $this->tenant->id, 'severity' => 'low', 'description' => 'x', 'detected_at' => now()->subDays(5),
            'lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED, 'resolved_at' => now()->subDay(), 'store_id' => null];
        Anomaly::create($base + ['rule_type' => 'stockout_risk', 'sku' => 'A', 'context' => ['revenue_impact' => 400]]);
        Anomaly::create($base + ['rule_type' => 'sales_drop', 'sku' => 'A', 'context' => ['revenue_impact' => 300]]);
        Anomaly::create($base + ['rule_type' => 'overstock', 'sku' => 'B', 'context' => ['inventory_value' => 9000, 'revenue_impact' => 9000]]);

        $obs = app(AnomalyRecoveryService::class)->observedInWindow($this->tenant->id);

        $this->assertEqualsWithDelta(400, $obs['amount'], 0.01);
        $this->assertSame(1, $obs['count']);
    }

    private function revenue(string $sku, int $fromAgo, int $toAgo, float $perDay): void
    {
        $rows = [];
        for ($d = $fromAgo; $d >= $toAgo; $d--) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => null, 'sku' => $sku, 'date' => Carbon::today()->subDays($d)->toDateString(),
                'units_sold' => 1, 'revenue' => $perDay, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);
    }

    public function test_recovery_is_measured_against_what_the_market_did_once_per_checkpoint(): void
    {
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_sku' => 'A', 'status' => 'resolved', 'revenue_at_risk' => 1000]);
        $this->member($inv, 'sales_drop', 'A', null, ['revenue_impact' => 1000], ['detected_at' => Carbon::today()->subDays(20)]);
        Action::create(['investigation_id' => $inv->id, 'action_type' => 'reorder', 'title' => 'Fix', 'status' => Action::STATUS_COMPLETED,
            'completed_at' => Carbon::today()->subDays(10)]);

        $this->revenue('A', 55, 28, 100);      // normal
        $this->revenue('A', 27, 11, 50);       // depressed
        $this->revenue('A', 10, 0, 110);       // after the action
        $this->revenue('REST', 55, 11, 1000);  // the rest of the business…
        $this->revenue('REST', 10, 0, 1200);   // …rose 20% on its own

        $svc = new OutcomeMeasurementService();
        $this->assertTrue($svc->measureInvestigation($inv->fresh()));
        $this->assertFalse($svc->measureInvestigation($inv->fresh()), 'same checkpoint → no second row');

        $m = OutcomeMeasurement::where('investigation_id', $inv->id)->sole();
        $this->assertSame(7, $m->details['checkpoint']);
        $this->assertEqualsWithDelta(120, $m->expected_value, 0.01, 'baseline 100 × market 1.2');
        $this->assertSame(OutcomeMeasurement::STATE_OBSERVED_RECOVERY, $m->outcome_state, '110 ≥ 90% of 120');
        // recovered = (min(110, 120) − 50 × 1.2) × 7 days = 350
        $this->assertEqualsWithDelta(350, $m->recovery_amount, 0.01);
        $this->assertEqualsWithDelta(350, InvestigationOutcome::where('investigation_id', $inv->id)->value('observed_recovery'), 0.01);
    }

    public function test_the_classify_command_labels_legacy_anomalies(): void
    {
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id]);
        $a = $this->member($inv, 'plan_variance', 'A', null, ['direction' => 'above', 'revenue_impact' => 10]);
        $b = $this->member($inv, 'phantom_inventory', 'B', null, ['inventory_value' => 10]);
        Anomaly::whereKey([$a->id, $b->id])->update(['value_type' => null]);

        $this->artisan('anomalies:classify-value')->assertSuccessful();

        $this->assertSame('upside', $a->fresh()->value_type);
        $this->assertSame('capital_at_cost', $b->fresh()->value_type);
    }
}
