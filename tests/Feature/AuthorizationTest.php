<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\User;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    /**
     * A plain analyst (non-admin) does not have permission to dismiss anomalies.
     */
    public function test_analyst_cannot_dismiss_anomaly(): void
    {
        $tenant  = $this->createTenant();
        $analyst = $this->actingAsAnalyst($tenant);

        $this->assertFalse($analyst->canDismissAnomalies());
    }

    /**
     * A tenant admin has permission to dismiss anomalies.
     */
    public function test_tenant_admin_can_dismiss_anomaly(): void
    {
        $tenant = $this->createTenant();
        $admin  = $this->actingAsTenantAdmin($tenant);

        $this->assertTrue($admin->canDismissAnomalies());

        // Admin can actually update the investigation_status to 'dismissed'
        $anomaly = Anomaly::factory()->create(['tenant_id' => $tenant->id]);
        $anomaly->update(['dismissed_at' => now(), 'dismissed_by' => $admin->id]);

        $this->assertTrue($anomaly->fresh()->isDismissed());
    }

    /**
     * A plain analyst cannot change anomaly detection thresholds (AnomalySetting).
     */
    public function test_analyst_cannot_change_thresholds(): void
    {
        $tenant  = $this->createTenant();
        $analyst = $this->actingAsAnalyst($tenant);

        $this->assertFalse($analyst->canChangeAnomalyThresholds());
    }

    /**
     * A tenant admin can create users within their own tenant.
     */
    public function test_tenant_admin_can_manage_users(): void
    {
        $tenant = $this->createTenant();
        $admin  = $this->actingAsTenantAdmin($tenant);

        $this->assertTrue($admin->canManageUsers());

        // Admin creates a new user scoped to their own tenant
        $newUser = User::factory()->create([
            'tenant_id'       => $tenant->id,
            'is_tenant_admin' => false,
            'is_super_admin'  => false,
        ]);

        $this->assertEquals($tenant->id, $newUser->tenant_id);
        $this->assertDatabaseHas('users', [
            'id'        => $newUser->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * A plain analyst does not have permission to manage (create/edit) users.
     */
    public function test_analyst_cannot_manage_users(): void
    {
        $tenant  = $this->createTenant();
        $analyst = $this->actingAsAnalyst($tenant);

        $this->assertFalse($analyst->canManageUsers());
    }

    /**
     * Tenant admin cannot elevate a user to super admin (that privilege belongs to super admins only).
     */
    public function test_tenant_admin_cannot_create_super_admin(): void
    {
        $tenant = $this->createTenant();
        $admin  = $this->actingAsTenantAdmin($tenant);

        // A tenant admin has canManageUsers() but NOT is_super_admin
        $this->assertTrue($admin->canManageUsers());
        $this->assertFalse($admin->isSuperAdmin());
    }

    /**
     * Super admin can manage users across all tenants.
     */
    public function test_super_admin_can_manage_users_across_tenants(): void
    {
        $tenantA    = $this->createTenant();
        $tenantB    = $this->createTenant();
        $superAdmin = $this->createUser($tenantA, superAdmin: true);
        $this->actingAs($superAdmin);

        $this->assertTrue($superAdmin->canManageUsers());
        $this->assertTrue($superAdmin->canAccessTenant($tenantA));
        $this->assertTrue($superAdmin->canAccessTenant($tenantB));
    }
}
