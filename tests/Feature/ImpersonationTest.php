<?php

namespace Tests\Feature;

use App\Http\Controllers\ImpersonationController;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * 2c — super-admin impersonation: enter a tenant as its admin, leave, and the
 * gates + audit around it.
 */
class ImpersonationTest extends TestCase
{
    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['slug' => 'acme']);
        $this->admin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $this->tenant->id,
            'email'     => 'admin@acme.com',
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create(['tenant_id' => $this->tenant->id, 'email' => 'root@autnyx.io']);
    }

    public function test_super_admin_can_enter_and_leave(): void
    {
        $super = $this->superAdmin();

        // Enter.
        $this->actingAs($super)
            ->get(route('ops.impersonate', ['tenant' => $this->tenant->id]))
            ->assertRedirect('/admin/acme');

        $this->assertAuthenticatedAs($this->admin);
        $this->assertSame($super->id, session(ImpersonationController::SESSION_KEY));
        // Regression: the session password hash must track the swapped-in user,
        // or AuthenticateSession force-logs-out (→ route('login') 500) on the
        // next panel request.
        $hashKey = 'password_hash_' . \Illuminate\Support\Facades\Auth::getDefaultDriver();
        $this->assertSame($this->admin->getAuthPassword(), session($hashKey));
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'  => $this->tenant->id,
            'event_type' => AuditLog::EVENT_IMPERSONATION_STARTED,
        ]);

        // Leave (now acting as the tenant admin, carried by the session).
        $this->get(route('ops.leave-impersonation'))->assertRedirect('/ops');

        $this->assertAuthenticatedAs($super);
        $this->assertNull(session(ImpersonationController::SESSION_KEY));
        $this->assertSame($super->getAuthPassword(), session($hashKey), 'hash restored to the super admin');
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'  => $this->tenant->id,
            'event_type' => AuditLog::EVENT_IMPERSONATION_STOPPED,
        ]);
    }

    public function test_non_super_admin_cannot_impersonate(): void
    {
        $this->actingAs($this->admin)
            ->get(route('ops.impersonate', ['tenant' => $this->tenant->id]))
            ->assertForbidden();

        $this->assertSame($this->admin->id, Auth::id(), 'still the same user');
    }

    public function test_cannot_impersonate_while_impersonating(): void
    {
        $super = $this->superAdmin();

        // Enter — the actor is now the tenant admin, no longer a super admin.
        $this->actingAs($super)->get(route('ops.impersonate', ['tenant' => $this->tenant->id]));

        // A second attempt is made as the tenant admin → not permitted.
        $this->get(route('ops.impersonate', ['tenant' => $this->tenant->id]))
            ->assertForbidden();
    }

    public function test_tenant_without_users_cannot_be_entered(): void
    {
        $empty = $this->createTenant(['slug' => 'empty']);
        $super = $this->superAdmin();

        $this->actingAs($super)
            ->get(route('ops.impersonate', ['tenant' => $empty->id]))
            ->assertNotFound();
    }
}
