<?php

namespace Database\Factories;

use App\Models\ProductSize;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'product_size_id' => ProductSize::factory(),
            'amount' => $this->faker->numberBetween(0, 100),
            'synced_amount' => null,
            'synced_at' => null,
        ];
    }

    public function synced(): static
    {
        return $this->state([
            'synced_amount' => function (array $attributes) {
                return $attributes['amount'];
            },
            'synced_at' => now(),
        ]);
    }
}
