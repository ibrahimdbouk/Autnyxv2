<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure the root "Autnyx" super-tenant exists.
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'autnyx'],
            [
                'name'     => 'Autnyx',
                'settings' => [],
            ]
        );

        // 2. Create or retrieve the Autnyx tenant's admin account. This is a
        //    TENANT admin of the Autnyx tenant — NOT a super admin. Platform/ops
        //    access belongs to the owner (config/autnyx.php › owner_email); extra
        //    super admins are minted by the owner, not seeded here.
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@autnyx.io')],
            [
                'name'              => 'Admin',
                'password'          => Hash::make(env('ADMIN_PASSWORD', 'Autnyx2026!')),
                'email_verified_at' => now(),
                'tenant_id'         => $tenant->id,
                'is_tenant_admin'   => true,
                'is_super_admin'    => false,
            ]
        );

        // Keep it a tenant admin of Autnyx on every deploy — and demote it if it
        // was previously a super admin.
        if (! $admin->wasRecentlyCreated) {
            $admin->update([
                'tenant_id'       => $tenant->id,
                'is_tenant_admin' => true,
                'is_super_admin'  => false,
            ]);
        }

        // 3. Ensure the protected platform OWNER exists (the super-super-admin).
        //    Identified by config('autnyx.owner_email'). The password is taken
        //    from OWNER_PASSWORD (a secret, never in the repo) on FIRST creation
        //    only — a later in-app password change is never overwritten by a
        //    deploy. If OWNER_PASSWORD is unset, a random one is used and the
        //    owner must reset it (they can never be locked out of the account
        //    itself, since it can't be deleted).
        $ownerEmail = config('autnyx.owner_email');

        if (! empty($ownerEmail)) {
            $owner = User::firstOrCreate(
                ['email' => $ownerEmail],
                [
                    'name'              => 'Owner',
                    'password'          => Hash::make(env('OWNER_PASSWORD') ?: Str::random(24)),
                    'email_verified_at' => now(),
                    'tenant_id'         => $tenant->id,
                    'is_super_admin'    => true,
                ]
            );

            // Always keep the owner a super admin and attached to the root tenant;
            // never touch their password here.
            if (! $owner->wasRecentlyCreated) {
                $owner->update([
                    'tenant_id'      => $owner->tenant_id ?: $tenant->id,
                    'is_super_admin' => true,
                ]);
            }
        }
    }
}
