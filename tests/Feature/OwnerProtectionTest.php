<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use RuntimeException;
use Tests\TestCase;

/**
 * Protected platform owner: the single super-super-admin who can never be
 * deleted or demoted, and is the only account able to mint super admins.
 */
class OwnerProtectionTest extends TestCase
{
    private Tenant $tenant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->owner = User::factory()->create([
            'email'          => config('autnyx.owner_email'),
            'tenant_id'      => $this->tenant->id,
            'is_super_admin' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create(['tenant_id' => $this->tenant->id, 'email' => 'other-super@x.com']);
    }

    public function test_is_owner_identifies_only_the_owner(): void
    {
        $this->assertTrue($this->owner->isOwner());
        $this->assertFalse($this->superAdmin()->isOwner());
    }

    public function test_owner_cannot_be_deleted(): void
    {
        try {
            $this->owner->delete();
            $this->fail('The owner should not be deletable.');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertDatabaseHas('users', ['id' => $this->owner->id]);
    }

    public function test_owner_cannot_be_demoted(): void
    {
        $this->owner->update(['is_super_admin' => false]);

        $this->assertTrue($this->owner->fresh()->is_super_admin, 'owner stays a super admin');
    }

    public function test_only_the_owner_can_mint_super_admins(): void
    {
        // A non-owner super admin cannot elevate anyone.
        $this->actingAs($this->superAdmin());
        $minted = User::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'email'          => 'wannabe@x.com',
            'is_super_admin' => true,
        ]);
        $this->assertFalse($minted->fresh()->is_super_admin, 'non-owner cannot grant super-admin');

        // The owner can.
        $this->actingAs($this->owner);
        $blessed = User::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'email'          => 'blessed@x.com',
            'is_super_admin' => true,
        ]);
        $this->assertTrue($blessed->fresh()->is_super_admin, 'owner can grant super-admin');
    }

    public function test_seeding_without_an_actor_can_create_super_admins(): void
    {
        // No authenticated actor (console / seeder) → trusted.
        $seeded = User::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'email'          => 'seeded-super@x.com',
            'is_super_admin' => true,
        ]);

        $this->assertTrue($seeded->fresh()->is_super_admin);
    }

    public function test_resource_gates_protect_the_owner(): void
    {
        $this->actingAs($this->superAdmin());
        $this->assertFalse(UserResource::canDelete($this->owner), 'no one deletes the owner');
        $this->assertFalse(UserResource::canEdit($this->owner), 'only the owner edits the owner');

        $this->actingAs($this->owner);
        $this->assertTrue(UserResource::canEdit($this->owner), 'the owner can edit themselves');
        $this->assertFalse(UserResource::canDelete($this->owner), 'even the owner cannot delete the owner');
    }
}
