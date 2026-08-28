<?php

namespace Tests\Feature;

use App\Http\Responses\LoginResponse;
use App\Models\User;
use Tests\TestCase;

/**
 * A super admin lands on the /ops control plane after login, not on a tenant's
 * data dashboard.
 */
class OpsLoginRedirectTest extends TestCase
{
    public function test_super_admin_is_sent_to_the_ops_control_plane(): void
    {
        $tenant = $this->createTenant();
        $super = User::factory()->superAdmin()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($super);

        $response = (new LoginResponse())->toResponse(request());

        $this->assertStringContainsString('/ops', $response->getTargetUrl());
    }
}
