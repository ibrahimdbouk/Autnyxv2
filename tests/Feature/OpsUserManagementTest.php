<?php

namespace Tests\Feature;

use App\Filament\Ops\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Ops user management + the Autnyx-admin demotion.
 */
class OpsUserManagementTest extends TestCase
{
    public function test_seeder_makes_the_autnyx_admin_a_tenant_admin_not_super(): void
    {
        // Simulate the existing production account: a super admin on the autnyx tenant.
        $tenant = Tenant::factory()->create(['slug' => 'autnyx', 'name' => 'Autnyx']);
        User::create([
            'name' => 'Admin', 'email' => 'admin@autnyx.io', 'password' => 'password123',
            'tenant_id' => $tenant->id, 'is_super_admin' => true,
        ]);

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@autnyx.io')->firstOrFail();
        $this->assertFalse($admin->is_super_admin, 'admin@autnyx.io should be demoted from super admin');
        $this->assertTrue($admin->is_tenant_admin);
        $this->assertSame($tenant->id, $admin->tenant_id);
    }

    public function test_only_super_admins_can_manage_users(): void
    {
        $tenant = $this->createTenant();

        $this->actingAs($this->createUser($tenant, admin: true));
        $this->assertFalse(UserResource::canViewAny(), 'a tenant admin must not manage users in ops');

        $this->actingAs($this->createUser($tenant, superAdmin: true));
        $this->assertTrue(UserResource::canViewAny());
    }

    public function test_ops_user_pages_render_for_a_super_admin(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->createUser($tenant, superAdmin: true));
        Filament::setCurrentPanel(Filament::getPanel('ops'));

        foreach (['index', 'create'] as $page) {
            $status = $this->get(UserResource::getUrl($page))->baseResponse->getStatusCode();
            $this->assertLessThan(500, $status, "Ops users '$page' returned HTTP $status");
        }
    }

    public function test_a_created_tenant_admin_is_scoped_to_its_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = User::create([
            'name' => 'Dana', 'email' => 'dana@acme.test', 'password' => 'password123',
            'tenant_id' => $tenant->id, 'is_tenant_admin' => true, 'is_super_admin' => false,
        ]);

        $this->assertSame('Tenant Admin', $user->roleLabel());
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('ops')), 'a tenant admin cannot reach ops');
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($user->canAccessTenant($tenant));
    }
}
