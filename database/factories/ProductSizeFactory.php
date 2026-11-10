<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSize>
 */
class ProductSizeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'chrt_id' => $this->faker->unique()->numberBetween(10_000_000, 99_999_999),
            'tech_size' => $this->faker->randomElement(['XS', 'S', 'M', 'L', 'XL']),
        ];
    }
}
