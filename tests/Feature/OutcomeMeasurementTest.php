<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\OutcomeMeasurement;
use App\Models\SalesTransaction;
use App\Services\Outcome\OutcomeMeasurementService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OutcomeMeasurementTest extends TestCase
{
    private function seedDailySales(int $tenantId, string $sku, Carbon $from, Carbon $to, float $perDay): void
    {
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            SalesTransaction::factory()->create([
                'tenant_id'    => $tenantId,
                'sku'          => $sku,
                'date'         => $d->toDateString(),
                'quantity'     => 1,
                'unit_price'   => $perDay,
                'total_amount' => $perDay,
            ]);
        }
    }

    public function test_recovery_is_observed_when_sales_return_to_baseline(): void
    {
        $tenant = $this->createTenant();
        $sku    = 'SKU-REC';

        $inv = Investigation::factory()->create([
            'tenant_id' => $tenant->id, 'primary_sku' => $sku, 'status' => 'resolved',
            'opened_at' => now()->subDays(30), 'resolved_at' => now()->subDays(5),
        ]);
        Anomaly::factory()->create([
            'tenant_id' => $tenant->id, 'investigation_id' => $inv->id, 'sku' => $sku,
            'detected_at' => now()->subDays(30),
        ]);
        Action::create([
            'investigation_id' => $inv->id, 'action_type' => 'reorder', 'title' => 'Reorder',
            'status' => Action::STATUS_COMPLETED, 'completed_at' => now()->subDays(20),
        ]);

        // Baseline (normal) before detection
        $this->seedDailySales($tenant->id, $sku, now()->subDays(65), now()->subDays(38), 100);
        // Depressed during the problem
        $this->seedDailySales($tenant->id, $sku, now()->subDays(37), now()->subDays(21), 10);
        // Recovered after the action
        $this->seedDailySales($tenant->id, $sku, now()->subDays(20), now()->subDays(6), 100);

        $measured = (new OutcomeMeasurementService())->measureInvestigation($inv->fresh(['anomalies', 'outcome']));
        $this->assertTrue($measured);

        $outcome = InvestigationOutcome::where('investigation_id', $inv->id)->first();
        $this->assertNotNull($outcome);
        $this->assertSame(OutcomeMeasurement::STATE_OBSERVED_RECOVERY, $outcome->outcome_state);
        $this->assertTrue(OutcomeMeasurement::where('investigation_id', $inv->id)->exists());
    }

    public function test_action_completion_alone_does_not_fabricate_recovery(): void
    {
        $tenant = $this->createTenant();
        $sku    = 'SKU-FLAT';

        $inv = Investigation::factory()->create([
            'tenant_id' => $tenant->id, 'primary_sku' => $sku, 'status' => 'resolved',
            'opened_at' => now()->subDays(30),
        ]);
        Anomaly::factory()->create([
            'tenant_id' => $tenant->id, 'investigation_id' => $inv->id, 'sku' => $sku,
            'detected_at' => now()->subDays(30),
        ]);
        Action::create([
            'investigation_id' => $inv->id, 'action_type' => 'reorder', 'title' => 'Reorder',
            'status' => Action::STATUS_COMPLETED, 'completed_at' => now()->subDays(20),
        ]);

        // Normal baseline, but sales stay depressed after the action
        $this->seedDailySales($tenant->id, $sku, now()->subDays(65), now()->subDays(38), 100);
        $this->seedDailySales($tenant->id, $sku, now()->subDays(37), now()->subDays(6), 10);

        (new OutcomeMeasurementService())->measureInvestigation($inv->fresh(['anomalies', 'outcome']));

        $outcome = InvestigationOutcome::where('investigation_id', $inv->id)->first();
        $this->assertNotNull($outcome);
        $this->assertNotSame(OutcomeMeasurement::STATE_OBSERVED_RECOVERY, $outcome->outcome_state);
        // No fabricated recovery
        $this->assertTrue(in_array($outcome->outcome_state, [
            OutcomeMeasurement::STATE_NO_MATERIAL_CHANGE,
            OutcomeMeasurement::STATE_INSUFFICIENT_EVIDENCE,
        ], true));
    }
}
