<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesTransaction;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\InvestigationCorrelationService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Locks in the revenue-impact scoring + minimum-revenue floor on the noisy
 * sales rules: a high-value SKU's spike is flagged and carries a revenue
 * impact that flows through to the investigation; a penny SKU's identical
 * percentage spike is floored out as noise.
 */
class DetectionImpactTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
    }

    private function sale(string $sku, string $date, float $qty): void
    {
        SalesTransaction::create([
            'tenant_id' => $this->tenant->id,
            'sku'       => $sku,
            'date'      => $date,
            'quantity'  => $qty,
        ]);
    }

    public function test_high_value_spike_flags_with_impact_penny_spike_is_floored(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SKU-BIG',  'name' => 'Big',  'selling_price' => 100]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SKU-TINY', 'name' => 'Tiny', 'selling_price' => 1]);

        $hist   = Carbon::today()->subDays(10)->format('Y-m-d'); // in the historical window
        $recent = Carbon::today()->subDays(1)->format('Y-m-d');  // in the recent window

        // Both SKUs: baseline ~5 units/period, recent 50 units → a ~900% spike.
        foreach (['SKU-BIG', 'SKU-TINY'] as $sku) {
            $this->sale($sku, $hist, 20);   // historical total (avg 5 across 4 periods)
            $this->sale($sku, $recent, 50); // recent surge
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $big = Anomaly::where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'sales_spike')
            ->where('sku', 'SKU-BIG')
            ->first();

        $this->assertNotNull($big, 'high-value spike should be flagged');
        $this->assertGreaterThanOrEqual(500, (float) ($big->context['revenue_impact'] ?? 0));

        $tiny = Anomaly::where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'sales_spike')
            ->where('sku', 'SKU-TINY')
            ->first();

        $this->assertNull($tiny, 'penny-value spike should be floored out as noise');

        // Impact flows through to the investigation so the list can rank by money.
        app(InvestigationCorrelationService::class)->correlateForTenant($this->tenant->id);

        $inv = Investigation::where('tenant_id', $this->tenant->id)
            ->where('primary_sku', 'SKU-BIG')
            ->first();

        $this->assertNotNull($inv);
        $this->assertGreaterThan(0, (float) $inv->revenue_at_risk);
    }

    public function test_receiving_discrepancy_gates_on_shortfall_value(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'PO-BIG',   'name' => 'Big',   'unit_cost' => 100]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'PO-CHEAP', 'name' => 'Cheap', 'unit_cost' => 1]);

        // Both are 90 units short on a 100-unit order (well past the 20% threshold).
        foreach (['PO-BIG' => 'P1', 'PO-CHEAP' => 'P2'] as $sku => $po) {
            PurchaseOrder::create([
                'tenant_id'     => $this->tenant->id,
                'po_number'     => $po,
                'supplier'      => 'Acme',
                'sku'           => $sku,
                'qty_ordered'   => 100,
                'qty_received'  => 10,
                'order_date'    => now()->subDays(20)->format('Y-m-d'),
                'received_date' => now()->subDays(2)->format('Y-m-d'),
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        // 90 × $100 = $9,000 → flagged; 90 × $1 = $90 → below the floor, suppressed.
        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'receiving_discrepancy')->where('sku', 'PO-BIG')->first()
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'receiving_discrepancy')->where('sku', 'PO-CHEAP')->first()
        );
    }
}
