<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'wb_warehouse_id' => $this->faker->unique()->numberBetween(100_000, 999_999),
            'office_id' => $this->faker->numberBetween(1, 500),
            'name' => 'Склад '.$this->faker->city(),
            'is_active' => true,
        ];
    }
}
