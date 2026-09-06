<?php

namespace Tests\Feature;

use App\Models\ContractViolation;
use App\Platform\Governance\ContractEvaluator;
use App\Platform\Governance\ContractRegistry;
use App\Platform\Governance\MeteringService;
use Tests\TestCase;

/**
 * P3.4 — data contracts (ingestion violations surface against a contract) and
 * per-app usage metering (metered/billable).
 */
class GovernanceTest extends TestCase
{
    public function test_a_clean_ingestion_breaches_no_contract(): void
    {
        $tenant = $this->createTenant();
        app(ContractRegistry::class)->define($tenant->id, 'sales_daily',
            requiredColumns: ['store', 'sku', 'qty'], freshnessSlaHours: 24, minRows: 10);

        $violations = app(ContractEvaluator::class)->evaluate($tenant->id, 'sales_daily', [
            'columns'      => ['store', 'sku', 'qty', 'price'],
            'row_count'    => 5000,
            'generated_at' => now()->subHours(2),
        ]);

        $this->assertCount(0, $violations);
        $this->assertCount(0, app(ContractEvaluator::class)->open($tenant->id));
    }

    public function test_breaches_are_detected_and_recorded(): void
    {
        $tenant = $this->createTenant();
        app(ContractRegistry::class)->define($tenant->id, 'sales_daily',
            requiredColumns: ['store', 'sku', 'qty'], freshnessSlaHours: 24, minRows: 10);

        $violations = app(ContractEvaluator::class)->evaluate($tenant->id, 'sales_daily', [
            'columns'      => ['store', 'sku'],      // missing 'qty'
            'row_count'    => 3,                      // below min 10
            'generated_at' => now()->subHours(48),   // stale vs 24h SLA
        ]);

        $kinds = $violations->pluck('kind')->all();
        $this->assertContains(ContractViolation::KIND_MISSING_COLUMNS, $kinds);
        $this->assertContains(ContractViolation::KIND_BELOW_MIN_ROWS, $kinds);
        $this->assertContains(ContractViolation::KIND_STALE, $kinds);
        $this->assertCount(3, app(ContractEvaluator::class)->open($tenant->id));
    }

    public function test_no_contract_means_nothing_to_enforce(): void
    {
        $tenant = $this->createTenant();

        $violations = app(ContractEvaluator::class)->evaluate($tenant->id, 'unknown_feed', [
            'columns' => [], 'row_count' => 0,
        ]);

        $this->assertCount(0, $violations);
    }

    public function test_usage_is_metered_per_app_and_metric(): void
    {
        $tenant = $this->createTenant();
        $metering = app(MeteringService::class);

        $metering->record($tenant->id, 'root_cause', 'investigations', 1, '2026-09');
        $metering->record($tenant->id, 'root_cause', 'investigations', 2, '2026-09');
        $metering->record($tenant->id, 'root_cause', 'detections', 5, '2026-09');

        $this->assertSame(3, $metering->usage($tenant->id, 'root_cause', 'investigations', '2026-09'));
        $this->assertSame(8, $metering->appTotal($tenant->id, 'root_cause', '2026-09'));

        $rows = $metering->tenantUsage($tenant->id, '2026-09');
        $this->assertCount(2, $rows); // two metrics
    }

    public function test_governance_is_tenant_scoped(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        app(MeteringService::class)->record($a->id, 'root_cause', 'investigations', 4, '2026-09');

        $this->assertSame(4, app(MeteringService::class)->appTotal($a->id, 'root_cause', '2026-09'));
        $this->assertSame(0, app(MeteringService::class)->appTotal($b->id, 'root_cause', '2026-09'));
    }
}
