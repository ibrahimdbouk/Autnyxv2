<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\TenantProvisioner;
use App\Services\Ops\TenantUsageService;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * 2a/2b — the control plane: panel access gate, tenant + first-admin
 * provisioning, and cross-tenant usage metrics.
 */
class OpsControlPlaneTest extends TestCase
{
    public function test_ops_panel_is_super_admin_only(): void
    {
        $tenant = $this->createTenant();
        $super  = User::factory()->superAdmin()->create(['tenant_id' => $tenant->id]);
        $admin  = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        $ops   = Filament::getPanel('ops');
        $panel = Filament::getPanel('admin');

        $this->assertTrue($super->canAccessPanel($ops));
        $this->assertFalse($admin->canAccessPanel($ops));
        $this->assertFalse($user->canAccessPanel($ops));

        // The tenant panel stays open to everyone.
        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_provisioner_creates_tenant_and_first_admin(): void
    {
        $super = User::factory()->superAdmin()->create(['tenant_id' => $this->createTenant()->id]);
        $this->actingAs($super);

        $tenant = app(TenantProvisioner::class)->create(
            ['name' => 'Globex Retail', 'plan' => Tenant::PLAN_ENTERPRISE],
            ['name' => 'Ops Admin', 'email' => 'ops@globex.com', 'password' => 'secret123'],
        );

        $this->assertSame('globex-retail', $tenant->slug, 'slug generated from name');
        $this->assertSame(Tenant::PLAN_ENTERPRISE, $tenant->plan);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);

        $admin = User::where('email', 'ops@globex.com')->first();
        $this->assertNotNull($admin);
        $this->assertSame($tenant->id, $admin->tenant_id);
        $this->assertTrue($admin->is_tenant_admin);
        $this->assertFalse($admin->is_super_admin);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'  => $tenant->id,
            'event_type' => AuditLog::EVENT_TENANT_CREATED,
        ]);
    }

    public function test_slug_collisions_are_resolved(): void
    {
        $p = app(TenantProvisioner::class);
        $a = $p->create(['name' => 'Acme']);
        $b = $p->create(['name' => 'Acme']);

        $this->assertSame('acme', $a->slug);
        $this->assertSame('acme-2', $b->slug);
    }

    public function test_usage_service_reports_per_tenant_and_overview(): void
    {
        $t1 = $this->createTenant(['name' => 'One', 'status' => Tenant::STATUS_ACTIVE]);
        $t2 = $this->createTenant(['name' => 'Two', 'status' => Tenant::STATUS_SUSPENDED]);

        User::factory()->count(3)->create(['tenant_id' => $t1->id]);
        User::factory()->count(1)->create(['tenant_id' => $t2->id]);

        $svc = app(TenantUsageService::class);

        $overview = $svc->overview();
        $this->assertSame(2, $overview['tenants']);
        $this->assertSame(1, $overview['active']);
        $this->assertSame(1, $overview['suspended']);
        $this->assertSame(4, $overview['users']);

        $rows = collect($svc->perTenant())->keyBy('id');
        $this->assertSame(3, $rows[$t1->id]['users']);
        $this->assertSame(1, $rows[$t2->id]['users']);
        $this->assertSame('One', $rows[$t1->id]['name']);
    }
}
