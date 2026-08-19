<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithFaker;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Creates a Tenant via factory, optionally overriding attributes.
     */
    protected function createTenant(array $attrs = []): Tenant
    {
        return Tenant::factory()->create($attrs);
    }

    /**
     * Creates a User belonging to the given tenant.
     *
     * @param  Tenant  $tenant
     * @param  bool  $admin      Sets is_tenant_admin
     * @param  bool  $superAdmin Sets is_super_admin
     */
    protected function createUser(Tenant $tenant, bool $admin = false, bool $superAdmin = false): User
    {
        return User::factory()->create([
            'tenant_id'       => $tenant->id,
            'is_tenant_admin' => $admin,
            'is_super_admin'  => $superAdmin,
        ]);
    }

    /**
     * Creates a tenant-admin user for the given tenant and sets it as the acting user.
     */
    protected function actingAsTenantAdmin(Tenant $tenant): User
    {
        $user = $this->createUser($tenant, admin: true);
        $this->actingAs($user);
        return $user;
    }

    /**
     * Creates a plain analyst user for the given tenant and sets it as the acting user.
     */
    protected function actingAsAnalyst(Tenant $tenant): User
    {
        $user = $this->createUser($tenant);
        $this->actingAs($user);
        return $user;
    }
}
