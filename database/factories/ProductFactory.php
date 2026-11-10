<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
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
            'nm_id' => $this->faker->unique()->numberBetween(100_000_000, 999_999_999),
            'vendor_code' => $this->faker->unique()->bothify('art-#####'),
            'name' => $this->faker->words(3, true),
        ];
    }
}
