<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Ops\TenantProvisioner;
use Tests\TestCase;

/**
 * Platform app entitlements — which apps a tenant may use. Defaults are
 * null-safe (fall back to Root-Cause), unknown values never grant access, and
 * the ops provisioner persists the assignment.
 */
class AppEntitlementsTest extends TestCase
{
    public function test_missing_apps_falls_back_to_root_cause(): void
    {
        $t = $this->createTenant(['apps' => null]);

        $this->assertSame([Tenant::APP_ROOT_CAUSE], $t->enabledApps());
        $this->assertTrue($t->hasApp(Tenant::APP_ROOT_CAUSE));
        $this->assertFalse($t->hasApp(Tenant::APP_ASSORTMENT));
    }

    public function test_empty_apps_falls_back_to_root_cause(): void
    {
        $t = $this->createTenant(['apps' => []]);

        $this->assertSame([Tenant::APP_ROOT_CAUSE], $t->enabledApps());
    }

    public function test_assigned_apps_are_honoured(): void
    {
        $t = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT]]);

        $this->assertTrue($t->hasApp(Tenant::APP_ASSORTMENT));
        $this->assertFalse($t->hasApp(Tenant::APP_TASK_EXECUTION));
    }

    public function test_unknown_app_values_never_grant_access(): void
    {
        $t = $this->createTenant(['apps' => ['bogus', 'not_an_app']]);

        // Nothing valid -> falls back to default rather than granting a bogus app.
        $this->assertSame([Tenant::APP_ROOT_CAUSE], $t->enabledApps());
        $this->assertFalse($t->hasApp('bogus'));
    }

    public function test_provisioner_persists_assigned_apps(): void
    {
        $tenant = app(TenantProvisioner::class)->create([
            'name' => 'Acme',
            'apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT],
        ]);

        $this->assertTrue($tenant->fresh()->hasApp(Tenant::APP_ASSORTMENT));
    }

    public function test_provisioner_defaults_apps_to_root_cause(): void
    {
        $tenant = app(TenantProvisioner::class)->create(['name' => 'Beta']);

        $this->assertSame([Tenant::APP_ROOT_CAUSE], $tenant->fresh()->enabledApps());
    }
}
