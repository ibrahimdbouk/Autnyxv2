<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name'              => $name,
            'slug'              => Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1000, 9999),
            'notification_email'=> $this->faker->safeEmail(),
            'notify_on_high'    => true,
            'notify_on_medium'  => false,
            'settings'          => [],
        ];
    }
}
