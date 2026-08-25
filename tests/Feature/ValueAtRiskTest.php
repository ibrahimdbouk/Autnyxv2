<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * B1: the aggregate "value at risk surfaced" figure sums context.revenue_impact
 * across a tenant's OPEN anomalies (dismissed ones and other tenants excluded).
 */
class ValueAtRiskTest extends TestCase
{
    public function test_value_at_risk_sums_open_anomaly_impacts(): void
    {
        $tenant = $this->createTenant();
        $other  = $this->createTenant();

        $this->makeAnomaly($tenant, 'stockout_risk', 1200.50);
        $this->makeAnomaly($tenant, 'inventory_shrinkage', 800.00);
        $this->makeAnomaly($tenant, 'overstock', 500.00, dismissed: true);   // excluded
        $this->makeAnomaly($tenant, 'sku_master_drift', null);               // no impact → 0
        $this->makeAnomaly($other,  'stockout_risk', 9999.00);               // other tenant

        $this->assertEqualsWithDelta(
            2000.50,
            Anomaly::estimatedValueAtRiskForTenant($tenant->id),
            0.01
        );
    }

    private function makeAnomaly(Tenant $tenant, string $rule, ?float $impact, bool $dismissed = false): void
    {
        Anomaly::create([
            'tenant_id'    => $tenant->id,
            'rule_type'    => $rule,
            'severity'     => 'medium',
            'sku'          => 'SKU-' . $rule,
            'description'  => 'test',
            'context'      => $impact === null ? [] : ['revenue_impact' => $impact],
            'detected_at'  => now(),
            'dismissed_at' => $dismissed ? now() : null,
        ]);
    }
}
