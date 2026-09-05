<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Platform\Metrics\MetricDefinition;
use App\Platform\Metrics\MetricRegistry;
use App\Platform\Metrics\MetricService;
use Tests\TestCase;

/**
 * P1.4 — the governed metric layer: a metric resolves through the registry with
 * its unit + version metadata, the Root-Cause KPIs are registered and correct,
 * and unknown metrics fail loudly.
 */
class MetricLayerTest extends TestCase
{
    public function test_a_registered_metric_resolves_with_governance_metadata(): void
    {
        $tenant = $this->createTenant();

        app(MetricRegistry::class)->register(new MetricDefinition(
            'test_metric', 'Test', MetricDefinition::UNIT_COUNT, 'A fixed test metric.', 3,
            fn (int $t) => 42,
        ));

        $value = app(MetricService::class)->value('test_metric', $tenant->id);

        $this->assertSame(42.0, $value->value);
        $this->assertSame(MetricDefinition::UNIT_COUNT, $value->unit);
        $this->assertSame(3, $value->version);
    }

    public function test_governed_revenue_at_risk_only_counts_open_investigations(): void
    {
        $tenant = $this->createTenant();

        Investigation::factory()->create(['tenant_id' => $tenant->id, 'status' => Investigation::STATUS_OPEN, 'revenue_at_risk' => 5000]);
        Investigation::factory()->create(['tenant_id' => $tenant->id, 'status' => Investigation::STATUS_IN_PROGRESS, 'revenue_at_risk' => 3000]);
        Investigation::factory()->create(['tenant_id' => $tenant->id, 'status' => 'resolved', 'revenue_at_risk' => 9999]);

        $value = app(MetricService::class)->value('revenue_at_risk', $tenant->id);

        $this->assertSame(8000.0, $value->value, 'resolved investigation is excluded');
        $this->assertSame(MetricDefinition::UNIT_MONEY, $value->unit);
    }

    public function test_unknown_metric_throws(): void
    {
        $tenant = $this->createTenant();

        $this->expectException(\InvalidArgumentException::class);
        app(MetricService::class)->value('does_not_exist', $tenant->id);
    }
}
