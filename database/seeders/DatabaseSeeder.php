<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create the first admin user if they don't exist yet.
        // Change these credentials immediately after first login.
        User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@autnyx.io')],
            [
                'name'              => 'Admin',
                'password'          => Hash::make(env('ADMIN_PASSWORD', 'Autnyx2026!')),
                'email_verified_at' => now(),
            ]
        );
    }
}
