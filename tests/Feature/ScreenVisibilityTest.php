<?php

namespace Tests\Feature;

use App\Filament\Resources\AnomalyResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Screens\ScreenRegistry;
use Tests\TestCase;

/**
 * 1a — admin/user roles + per-screen visibility.
 *
 * Admins always see everything; a plain user sees a gate-able screen only when
 * it is in their `visible_screens` (null = unrestricted). Verified at the model
 * level and through a real Filament Resource gate.
 */
class ScreenVisibilityTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
    }

    private function user(array $attrs = []): User
    {
        return User::factory()->create(['tenant_id' => $this->tenant->id] + $attrs);
    }

    public function test_admins_see_every_screen(): void
    {
        $super  = User::factory()->superAdmin()->create(['tenant_id' => $this->tenant->id, 'visible_screens' => []]);
        $tenant = User::factory()->tenantAdmin()->create(['tenant_id' => $this->tenant->id, 'visible_screens' => []]);

        foreach (ScreenRegistry::keys() as $key) {
            $this->assertTrue($super->canSeeScreen($key), "super admin sees $key");
            $this->assertTrue($tenant->canSeeScreen($key), "tenant admin sees $key");
        }
    }

    public function test_null_visible_screens_is_unrestricted(): void
    {
        $u = $this->user(['visible_screens' => null]);

        foreach (ScreenRegistry::keys() as $key) {
            $this->assertTrue($u->canSeeScreen($key), "null → sees $key");
        }
    }

    public function test_explicit_list_restricts_to_its_keys(): void
    {
        $u = $this->user(['visible_screens' => ['anomalies', 'investigations']]);

        $this->assertTrue($u->canSeeScreen('anomalies'));
        $this->assertTrue($u->canSeeScreen('investigations'));
        $this->assertFalse($u->canSeeScreen('sales'));
        $this->assertFalse($u->canSeeScreen('purchase_orders'));
    }

    public function test_empty_list_hides_every_gateable_screen(): void
    {
        $u = $this->user(['visible_screens' => []]);

        foreach (ScreenRegistry::keys() as $key) {
            $this->assertFalse($u->canSeeScreen($key), "empty → hides $key");
        }
    }

    public function test_resource_gate_reflects_visibility(): void
    {
        $allowed = $this->user(['visible_screens' => ['anomalies']]);
        $this->actingAs($allowed);
        $this->assertTrue(AnomalyResource::canViewAny());
        $this->assertTrue(AnomalyResource::shouldRegisterNavigation());

        $denied = $this->user(['visible_screens' => ['sales']]);
        $this->actingAs($denied);
        $this->assertFalse(AnomalyResource::canViewAny(), 'anomalies not granted → gate closed');
        $this->assertFalse(AnomalyResource::shouldRegisterNavigation());

        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $this->tenant->id, 'visible_screens' => []]);
        $this->actingAs($admin);
        $this->assertTrue(AnomalyResource::canViewAny(), 'admin always passes');
    }

    public function test_access_change_is_audit_logged(): void
    {
        $actor = User::factory()->tenantAdmin()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($actor);

        $u = $this->user(['visible_screens' => ['anomalies']]);
        $u->update(['visible_screens' => ['anomalies', 'sales']]);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'  => $this->tenant->id,
            'user_id'    => $actor->id,
            'event_type' => \App\Models\AuditLog::EVENT_SCREEN_ACCESS_CHANGED,
        ]);
    }
}
