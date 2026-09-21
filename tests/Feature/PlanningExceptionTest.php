<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\PlanningException;
use App\Services\Integrations\PlanningExceptionIngestor;
use Tests\TestCase;

/**
 * Inbound F&R exceptions under the HYBRID precedence model (PlanningExceptionIngestor):
 * a type Autnyx also detects corroborates a live anomaly (never a new investigation);
 * an upstream-only type raises its own first-class anomaly; and an exception that drops
 * out of a source's snapshot is reconciled to resolved. See claude/api-integration-library.md.
 */
class PlanningExceptionTest extends TestCase
{
    private function feed(int $tenantId): array
    {
        $conn = ApiConnection::create([
            'tenant_id' => $tenantId, 'name' => 'RELEX', 'provider' => 'relex',
            'base_url' => 'https://relex.test', 'auth_type' => 'bearer',
            'auth_config' => ['token' => 't'], 'is_active' => true, 'status' => ApiConnection::STATUS_NEVER,
        ]);
        $feed = ApiFeed::create([
            'api_connection_id' => $conn->id, 'tenant_id' => $tenantId,
            'data_type' => ApiFeed::DATA_TYPE_PLANNING_EXCEPTION, 'endpoint' => '/exceptions',
            'records_path' => 'items', 'page_strategy' => 'none', 'enabled' => true,
        ]);

        return [$conn, $feed];
    }

    public function test_corroboration_attaches_to_a_live_anomaly_and_raises_severity(): void
    {
        $tenant = $this->createTenant();
        [$conn, $feed] = $this->feed($tenant->id);

        // An open Autnyx anomaly Autnyx detected itself.
        $anomaly = Anomaly::create([
            'tenant_id' => $tenant->id, 'rule_type' => 'sales_drop', 'severity' => Anomaly::SEVERITY_LOW,
            'sku' => 'COR-1', 'store_id' => null, 'description' => 'Sales drop', 'context' => [],
            'detected_at' => now(),
        ]);

        // A forecast-type F&R exception for the same SKU → corroboration, not a new anomaly.
        $n = app(PlanningExceptionIngestor::class)->ingest($conn, $feed, [
            ['sku' => 'COR-1', 'type' => 'Forecast error', 'severity' => 'high', 'message' => 'Big miss vs plan'],
        ]);
        $this->assertSame(1, $n);

        $anomaly->refresh();
        $this->assertSame(Anomaly::SEVERITY_HIGH, $anomaly->severity, 'severity is raised to the exception\'s');
        $this->assertNotEmpty($anomaly->context['external_corroboration'] ?? []);
        $this->assertSame('relex', $anomaly->context['external_corroboration'][0]['source']);

        // The exception is linked, and NO first-class anomaly was created.
        $ex = PlanningException::where('tenant_id', $tenant->id)->where('sku', 'COR-1')->first();
        $this->assertSame(PlanningException::DISPOSITION_CORROBORATION, $ex->disposition);
        $this->assertSame(PlanningException::STATUS_MATCHED, $ex->status);
        $this->assertSame($anomaly->id, $ex->matched_anomaly_id);
        $this->assertSame(0, Anomaly::where('tenant_id', $tenant->id)->where('rule_type', 'planning_exception')->count());
    }

    public function test_unmatched_corroboration_is_parked_not_flagged(): void
    {
        $tenant = $this->createTenant();
        [$conn, $feed] = $this->feed($tenant->id);

        // Stockout-type exception, but Autnyx has no open anomaly for this SKU.
        app(PlanningExceptionIngestor::class)->ingest($conn, $feed, [
            ['sku' => 'ORPHAN', 'type' => 'Stockout risk', 'severity' => 'medium'],
        ]);

        $ex = PlanningException::where('tenant_id', $tenant->id)->where('sku', 'ORPHAN')->first();
        $this->assertSame(PlanningException::STATUS_OPEN, $ex->status, 'parked as a review signal');
        $this->assertNull($ex->matched_anomaly_id);
        $this->assertSame(0, Anomaly::where('tenant_id', $tenant->id)->count(), 'no standalone anomaly');
    }

    public function test_upstream_only_type_raises_a_first_class_anomaly(): void
    {
        $tenant = $this->createTenant();
        [$conn, $feed] = $this->feed($tenant->id);

        app(PlanningExceptionIngestor::class)->ingest($conn, $feed, [
            ['sku' => 'SUP-1', 'type' => 'Supply shortage', 'severity' => 'high', 'message' => 'Vendor cannot fulfil'],
        ]);

        $ex = PlanningException::where('tenant_id', $tenant->id)->where('sku', 'SUP-1')->first();
        $this->assertSame('supply_risk', $ex->category);
        $this->assertSame(PlanningException::DISPOSITION_FIRST_CLASS, $ex->disposition);
        $this->assertSame(PlanningException::STATUS_MATCHED, $ex->status);

        $anomaly = Anomaly::where('tenant_id', $tenant->id)->where('rule_type', 'planning_exception')->where('sku', 'SUP-1')->first();
        $this->assertNotNull($anomaly, 'an upstream-only exception raises its own anomaly');
        $this->assertSame(Anomaly::SEVERITY_HIGH, $anomaly->severity);
        $this->assertSame($anomaly->id, $ex->anomaly_id);
    }

    public function test_snapshot_reconcile_resolves_a_cleared_exception_and_its_anomaly(): void
    {
        $tenant = $this->createTenant();
        [$conn, $feed] = $this->feed($tenant->id);
        $ingestor = app(PlanningExceptionIngestor::class);

        // Poll 1: the exception is open.
        $ingestor->ingest($conn, $feed, [
            ['sku' => 'SUP-2', 'type' => 'Allocation constraint', 'severity' => 'high'],
        ]);
        $anomaly = Anomaly::where('tenant_id', $tenant->id)->where('rule_type', 'planning_exception')->where('sku', 'SUP-2')->first();
        $this->assertNotNull($anomaly);

        // Poll 2: the same source no longer reports it → cleared upstream.
        $ingestor->ingest($conn, $feed, []);

        $ex = PlanningException::where('tenant_id', $tenant->id)->where('sku', 'SUP-2')->first();
        $this->assertSame(PlanningException::STATUS_RESOLVED, $ex->status);
        $this->assertSame(Anomaly::LIFECYCLE_RESOLVED, $anomaly->refresh()->lifecycle_state);
    }
}
