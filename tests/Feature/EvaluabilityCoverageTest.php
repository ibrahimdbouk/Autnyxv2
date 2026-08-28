<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Services\Anomaly\AnomalyDetectionService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * R2c — the per-rule evaluability decision table. Strict coverage gating (a
 * cleared subject stays dormant when its input vanished) now covers the
 * financial-sales, cost, and capital families in addition to inventory/demand;
 * the families where a gap cannot masquerade as recovery still fall back to
 * "rule ran and didn't flag = cleared" on purpose.
 *
 * Tested directly against the private closure so the mapping is pinned without a
 * full detection run.
 */
class EvaluabilityCoverageTest extends TestCase
{
    /** @var array<string,true> */
    private array $invSkus  = ['SKU-INV' => true];
    /** @var array<string,true> */
    private array $demSkus  = ['SKU-SALES' => true];
    /** @var array<string,true> */
    private array $costSkus = ['SKU-COST' => true];

    /**
     * Resolve the private evaluability closure for a rule against fixed coverage.
     */
    private function evaluable(string $ruleType): \Closure
    {
        $svc = app(AnomalyDetectionService::class);
        $m = new ReflectionMethod($svc, 'evaluabilityFor');
        $m->setAccessible(true);

        // [invPairs, invSkus, demPairs, demSkus, costSkus] — pairs unused here.
        return $m->invoke($svc, $ruleType, [], $this->invSkus, [], $this->demSkus, $this->costSkus);
    }

    private function anomaly(?string $sku, ?int $storeId = null): Anomaly
    {
        return new Anomaly(['sku' => $sku, 'store_id' => $storeId]);
    }

    public function test_cost_spike_gates_on_purchase_order_cost_presence(): void
    {
        $eval = $this->evaluable('cost_spike');
        $this->assertTrue($eval($this->anomaly('SKU-COST')), 'PO cost data present → evaluable');
        $this->assertFalse($eval($this->anomaly('SKU-GONE')), 'PO cost data vanished → dormant, not recovered');
    }

    public function test_financial_sales_rules_gate_on_recent_sales(): void
    {
        foreach (['price_anomaly', 'margin_erosion'] as $rule) {
            $eval = $this->evaluable($rule);
            $this->assertTrue($eval($this->anomaly('SKU-SALES')), "$rule: recent sales → evaluable");
            $this->assertFalse($eval($this->anomaly('SKU-QUIET')), "$rule: no recent sales → dormant");
        }
    }

    public function test_slow_moving_capital_gates_on_inventory_presence(): void
    {
        $eval = $this->evaluable('slow_moving_capital');
        $this->assertTrue($eval($this->anomaly('SKU-INV')), 'inventory row present → evaluable');
        $this->assertFalse($eval($this->anomaly('SKU-GONE')), 'inventory data vanished → dormant');
    }

    public function test_ungated_families_keep_the_fallback(): void
    {
        // A gap cannot masquerade as recovery here → "ran and didn't flag = cleared".
        foreach (['discount_signal', 'po_overdue', 'supplier_fill_rate', 'import_frequency_gap', 'sku_master_drift'] as $rule) {
            $eval = $this->evaluable($rule);
            $this->assertTrue($eval($this->anomaly('ANY-SKU')), "$rule stays evaluable-by-default");
        }
    }

    public function test_null_sku_is_always_evaluable(): void
    {
        // Tenant/store-level subjects (revenue_concentration_risk, store_outlier).
        $this->assertTrue(($this->evaluable('price_anomaly'))($this->anomaly(null)));
        $this->assertTrue(($this->evaluable('cost_spike'))($this->anomaly(null)));
    }

    public function test_inventory_and_demand_families_unchanged(): void
    {
        $inv = $this->evaluable('stockout_risk');
        $this->assertTrue($inv($this->anomaly('SKU-INV')));
        $this->assertFalse($inv($this->anomaly('SKU-GONE')));

        $dem = $this->evaluable('sales_drop');
        $this->assertTrue($dem($this->anomaly('SKU-SALES')));
        $this->assertFalse($dem($this->anomaly('SKU-QUIET')));
    }
}
