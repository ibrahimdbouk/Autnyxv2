<?php

namespace Database\Factories;

use App\Models\InventoryLevel;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLevel>
 *
 * Note: InventoryLevel uses `on_hand_qty` and `as_of_date` column names.
 */
class InventoryLevelFactory extends Factory
{
    protected $model = InventoryLevel::class;

    public function definition(): array
    {
        return [
            'tenant_id'     => Tenant::factory(),
            'sku'           => 'SKU-TEST',
            'on_hand_qty'   => $this->faker->numberBetween(10, 500),
            'reorder_point' => 50,
            'as_of_date'    => now()->toDateString(),
        ];
    }

    /**
     * Set on_hand_qty to zero (stockout scenario).
     */
    public function outOfStock(): static
    {
        return $this->state(['on_hand_qty' => 0]);
    }
}
