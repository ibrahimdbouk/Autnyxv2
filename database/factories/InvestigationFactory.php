<?php

namespace Database\Factories;

use App\Models\Investigation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Investigation>
 */
class InvestigationFactory extends Factory
{
    protected $model = Investigation::class;

    public function definition(): array
    {
        return [
            'tenant_id'     => Tenant::factory(),
            'title'         => $this->faker->sentence(6),
            'description'   => $this->faker->paragraph(),
            'status'        => 'open',
            'priority'      => 'medium',
            'anomaly_count' => 1,
            'opened_at'     => now(),
        ];
    }

    /**
     * Set status to in_progress.
     */
    public function inProgress(): static
    {
        return $this->state(['status' => 'in_progress']);
    }

    /**
     * Set status to resolved with a resolved_at timestamp.
     */
    public function resolved(): static
    {
        return $this->state([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);
    }
}
