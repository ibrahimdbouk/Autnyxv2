<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name'              => $this->faker->name(),
            'email'             => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => self::$password ??= Hash::make('password'),
            'remember_token'    => Str::random(10),
            'tenant_id'         => null,
            'is_super_admin'    => false,
            'is_tenant_admin'   => false,
        ];
    }

    /**
     * Mark the user as a tenant admin.
     */
    public function tenantAdmin(): static
    {
        return $this->state(['is_tenant_admin' => true]);
    }

    /**
     * Mark the user as a super admin.
     */
    public function superAdmin(): static
    {
        return $this->state(['is_super_admin' => true]);
    }
}
