<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ExportAudit;
use Tests\TestCase;

/**
 * 3b — tenant isolation is a core GDPR / SOC 2 / ISO control. A regular user
 * must never reach another tenant's data or exports; exports are audited.
 */
class TenantIsolationTest extends TestCase
{
    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->createTenant(['slug' => 'aaa']);
        $this->b = $this->createTenant(['slug' => 'bbb']);
    }

    public function test_can_access_tenant_is_scoped(): void
    {
        $userA = User::factory()->create(['tenant_id' => $this->a->id]);
        $this->assertTrue($userA->canAccessTenant($this->a));
        $this->assertFalse($userA->canAccessTenant($this->b), 'no cross-tenant access');

        $super = User::factory()->superAdmin()->create(['tenant_id' => $this->a->id]);
        $this->assertTrue($super->canAccessTenant($this->b), 'super admin reaches any tenant');
    }

    public function test_cross_tenant_anomaly_export_is_forbidden(): void
    {
        $userA = User::factory()->create(['tenant_id' => $this->a->id]);

        $anomalyInB = Anomaly::create([
            'tenant_id'   => $this->b->id,
            'rule_type'   => 'stockout_risk',
            'severity'    => 'high',
            'sku'         => 'SKU-B',
            'store_id'    => null,
            'description' => 'b-only',
            'detected_at' => now(),
        ]);

        $this->actingAs($userA)
            ->get(route('anomaly.report.pdf', ['id' => $anomalyInB->id]))
            ->assertForbidden();
    }

    public function test_exports_are_audited(): void
    {
        $userA = User::factory()->create(['tenant_id' => $this->a->id]);
        $this->actingAs($userA);

        ExportAudit::log($this->a->id, 'recovery report', 'pdf');

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'  => $this->a->id,
            'user_id'    => $userA->id,
            'event_type' => AuditLog::EVENT_DATA_EXPORTED,
        ]);
    }
}
