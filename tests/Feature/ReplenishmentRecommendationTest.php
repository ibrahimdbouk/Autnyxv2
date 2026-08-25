<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\SkuReplenishment;
use App\Models\Store;
use App\Services\Anomaly\ReplenishmentRecommendationService;
use Tests\TestCase;

/**
 * B4 slice 2 / B5: transfer + purchase-order recommendations for a stockout, and
 * adopting one as a tracked action. Recommendation-only — nothing is executed.
 */
class ReplenishmentRecommendationTest extends TestCase
{
    private function svc(): ReplenishmentRecommendationService
    {
        return app(ReplenishmentRecommendationService::class);
    }

    public function test_recommends_transfer_from_surplus_store_and_a_po(): void
    {
        $tenant = $this->createTenant();
        $s1 = Store::create(['tenant_id' => $tenant->id, 'name' => 'Needy', 'code' => 'ST01']);
        $s2 = Store::create(['tenant_id' => $tenant->id, 'name' => 'Donor', 'code' => 'ST02']);
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'SR1', 'name' => 'Item', 'selling_price' => 20, 'unit_cost' => 10]);

        // Needy store target 40, on hand 5 → needs 35. Donor target 10, on hand 60 → surplus 50.
        SkuReplenishment::create(['tenant_id' => $tenant->id, 'sku' => 'SR1', 'store_id' => $s1->id,
            'order_up_to' => 40, 'reorder_point' => 20, 'on_hand' => 5, 'unit_cost' => 10, 'supplier' => 'ACME',
            'daily_rate' => 2, 'lead_time_days' => 4, 'computed_at' => now()]);
        SkuReplenishment::create(['tenant_id' => $tenant->id, 'sku' => 'SR1', 'store_id' => $s2->id,
            'order_up_to' => 10, 'reorder_point' => 5, 'on_hand' => 60, 'unit_cost' => 10, 'supplier' => 'ACME',
            'daily_rate' => 1, 'lead_time_days' => 4, 'computed_at' => now()]);

        InventoryLevel::create(['tenant_id' => $tenant->id, 'store_id' => $s1->id, 'sku' => 'SR1', 'on_hand_qty' => 5, 'as_of_date' => now()->subDay()->format('Y-m-d')]);
        InventoryLevel::create(['tenant_id' => $tenant->id, 'store_id' => $s2->id, 'sku' => 'SR1', 'on_hand_qty' => 60, 'as_of_date' => now()->subDay()->format('Y-m-d')]);

        $anomaly = Anomaly::create(['tenant_id' => $tenant->id, 'rule_type' => 'stockout_risk', 'severity' => 'high',
            'sku' => 'SR1', 'store_id' => $s1->id, 'description' => 'stockout', 'detected_at' => now()]);

        $recs = $this->svc()->forAnomaly($anomaly);
        $kinds = collect($recs)->pluck('kind')->all();

        $this->assertContains('transfer', $kinds);
        $this->assertContains('purchase_order', $kinds);

        $transfer = collect($recs)->firstWhere('kind', 'transfer');
        $this->assertSame($s2->id, $transfer['from_store_id']);
        $this->assertSame(35.0, $transfer['qty']);        // min(need 35, surplus 50)
        $this->assertSame(Action::TYPE_TRANSFER, $transfer['action_type']);

        $po = collect($recs)->firstWhere('kind', 'purchase_order');
        $this->assertSame(35.0, $po['qty']);
        $this->assertSame('ACME', $po['supplier']);
    }

    public function test_no_donor_yields_po_only(): void
    {
        $tenant = $this->createTenant();
        $s1 = Store::create(['tenant_id' => $tenant->id, 'name' => 'Only', 'code' => 'ST01']);
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'SR2', 'name' => 'Item', 'selling_price' => 20, 'unit_cost' => 10]);

        SkuReplenishment::create(['tenant_id' => $tenant->id, 'sku' => 'SR2', 'store_id' => $s1->id,
            'order_up_to' => 40, 'reorder_point' => 20, 'on_hand' => 5, 'unit_cost' => 10, 'supplier' => 'ACME',
            'daily_rate' => 2, 'lead_time_days' => 4, 'computed_at' => now()]);
        InventoryLevel::create(['tenant_id' => $tenant->id, 'store_id' => $s1->id, 'sku' => 'SR2', 'on_hand_qty' => 5, 'as_of_date' => now()->subDay()->format('Y-m-d')]);

        $anomaly = Anomaly::create(['tenant_id' => $tenant->id, 'rule_type' => 'stockout_risk', 'severity' => 'medium',
            'sku' => 'SR2', 'store_id' => $s1->id, 'description' => 'stockout', 'detected_at' => now()]);

        $kinds = collect($this->svc()->forAnomaly($anomaly))->pluck('kind')->all();
        $this->assertNotContains('transfer', $kinds);
        $this->assertContains('purchase_order', $kinds);
    }

    public function test_adopt_creates_a_tracked_action_only(): void
    {
        $tenant = $this->createTenant();
        $user   = $this->createUser($tenant);
        $s1 = Store::create(['tenant_id' => $tenant->id, 'name' => 'Needy', 'code' => 'ST01']);
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'SR3', 'name' => 'Item', 'selling_price' => 20, 'unit_cost' => 10]);
        SkuReplenishment::create(['tenant_id' => $tenant->id, 'sku' => 'SR3', 'store_id' => $s1->id,
            'order_up_to' => 40, 'reorder_point' => 20, 'on_hand' => 5, 'unit_cost' => 10, 'supplier' => 'ACME',
            'daily_rate' => 2, 'lead_time_days' => 4, 'computed_at' => now()]);
        InventoryLevel::create(['tenant_id' => $tenant->id, 'store_id' => $s1->id, 'sku' => 'SR3', 'on_hand_qty' => 5, 'as_of_date' => now()->subDay()->format('Y-m-d')]);

        $anomaly = Anomaly::create(['tenant_id' => $tenant->id, 'rule_type' => 'stockout_risk', 'severity' => 'high',
            'sku' => 'SR3', 'store_id' => $s1->id, 'description' => 'stockout', 'detected_at' => now()]);

        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id, 'status' => 'open']);
        $anomaly->update(['investigation_id' => $inv->id]);

        $recs = $this->svc()->forInvestigation($inv->fresh());
        $this->assertNotEmpty($recs);

        $action = $this->svc()->adopt($inv, $recs[0], $user->id);

        $this->assertDatabaseHas('actions', [
            'id'               => $action->id,
            'investigation_id' => $inv->id,
            'status'           => Action::STATUS_UNASSIGNED,
            'created_by'       => $user->id,
        ]);
        $this->assertSame($recs[0]['action_type'], $action->action_type);
        $this->assertSame($anomaly->id, $action->anomaly_id);
    }
}
