<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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

        // 2. Create or retrieve the initial super-admin account.
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@autnyx.io')],
            [
                'name'              => 'Admin',
                'password'          => Hash::make(env('ADMIN_PASSWORD', 'Autnyx2026!')),
                'email_verified_at' => now(),
                'tenant_id'         => $tenant->id,
                'is_super_admin'    => true,
            ]
        );

        // If the user already existed (e.g. created via artisan), backfill tenant + super-admin flag.
        if (! $admin->wasRecentlyCreated) {
            $admin->update([
                'tenant_id'      => $tenant->id,
                'is_super_admin' => true,
            ]);
        }
    }
}
