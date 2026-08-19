<?php

namespace Database\Factories;

use App\Models\SalesTransaction;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesTransaction>
 */
class SalesTransactionFactory extends Factory
{
    protected $model = SalesTransaction::class;

    public function definition(): array
    {
        $qty        = $this->faker->numberBetween(1, 100);
        $unitPrice  = $this->faker->randomFloat(2, 5.00, 200.00);

        return [
            'tenant_id'    => Tenant::factory(),
            'sku'          => 'SKU-TEST',
            'date'         => now()->toDateString(),
            'quantity'     => $qty,
            'unit_price'   => $unitPrice,
            'total_amount' => round($qty * $unitPrice, 4),
        ];
    }

    /**
     * Set date to a specific value (Carbon or date string).
     */
    public function onDate(string $date): static
    {
        return $this->state(['date' => $date]);
    }

    /**
     * Assign a transaction_id (for duplicate detection tests).
     */
    public function withTransactionId(string $transactionId): static
    {
        return $this->state(['transaction_id' => $transactionId]);
    }
}
