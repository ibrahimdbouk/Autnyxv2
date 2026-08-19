<?php

namespace Database\Factories;

use App\Models\Anomaly;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Anomaly>
 */
class AnomalyFactory extends Factory
{
    protected $model = Anomaly::class;

    public function definition(): array
    {
        return [
            'tenant_id'            => Tenant::factory(),
            'rule_type'            => 'sales_drop',
            'severity'             => 'medium',
            'sku'                  => 'SKU-TEST',
            'description'          => $this->faker->sentence(),
            'context'              => [],
            'detected_at'          => now(),
            'investigation_status' => 'detected',
        ];
    }

    /**
     * Set the rule type.
     */
    public function forRule(string $ruleType): static
    {
        return $this->state(['rule_type' => $ruleType]);
    }

    /**
     * Set severity to high.
     */
    public function high(): static
    {
        return $this->state(['severity' => 'high']);
    }

    /**
     * Set severity to low.
     */
    public function low(): static
    {
        return $this->state(['severity' => 'low']);
    }
}
