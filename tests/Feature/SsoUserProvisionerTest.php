<?php

namespace Tests\Feature;

use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sso\SsoUserProvisioner;
use RuntimeException;
use Tests\TestCase;

/**
 * 1b — turning OIDC claims into a tenant-scoped user: match, JIT-create, domain
 * restriction, and admin-group mapping.
 */
class SsoUserProvisionerTest extends TestCase
{
    private Tenant $tenant;
    private SsoUserProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->provisioner = new SsoUserProvisioner();
    }

    private function connection(array $overrides = []): SsoConnection
    {
        return SsoConnection::create(array_merge([
            'tenant_id'        => $this->tenant->id,
            'enabled'          => true,
            'issuer'           => 'https://idp.test',
            'client_id'        => 'c',
            'client_secret'    => 's',
            'email_claim'      => 'email',
            'name_claim'       => 'name',
            'jit_provisioning' => true,
        ], $overrides));
    }

    public function test_matches_existing_user_case_insensitively(): void
    {
        $existing = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'Jane@Acme.com']);
        $conn = $this->connection();

        $user = $this->provisioner->resolve($conn, ['email' => 'jane@acme.com', 'name' => 'Jane']);

        $this->assertTrue($user->is($existing));
    }

    public function test_jit_creates_a_new_user_in_the_tenant(): void
    {
        $conn = $this->connection();

        $user = $this->provisioner->resolve($conn, ['email' => 'new@acme.com', 'name' => 'New Person']);

        $this->assertTrue($user->wasRecentlyCreated);
        $this->assertSame($this->tenant->id, $user->tenant_id);
        $this->assertSame('New Person', $user->name);
        $this->assertFalse($user->is_tenant_admin);
    }

    public function test_domain_restriction_blocks_other_domains(): void
    {
        $conn = $this->connection(['allowed_domains' => ['acme.com']]);

        $this->expectException(RuntimeException::class);
        $this->provisioner->resolve($conn, ['email' => 'intruder@evil.com']);
    }

    public function test_jit_disabled_rejects_unknown_user(): void
    {
        $conn = $this->connection(['jit_provisioning' => false]);

        $this->expectException(RuntimeException::class);
        $this->provisioner->resolve($conn, ['email' => 'nobody@acme.com']);
    }

    public function test_admin_group_claim_promotes_to_tenant_admin(): void
    {
        $conn = $this->connection([
            'admin_group_claim' => 'groups',
            'admin_group_value' => 'autnyx-admins',
        ]);

        $user = $this->provisioner->resolve($conn, [
            'email'  => 'boss@acme.com',
            'groups' => ['everyone', 'autnyx-admins'],
        ]);

        $this->assertTrue($user->is_tenant_admin);
    }

    public function test_missing_email_claim_is_rejected(): void
    {
        $conn = $this->connection();

        $this->expectException(RuntimeException::class);
        $this->provisioner->resolve($conn, ['name' => 'No Email']);
    }
}
