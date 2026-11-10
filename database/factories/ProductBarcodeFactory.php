<?php

namespace Database\Factories;

use App\Models\ProductBarcode;
use App\Models\ProductSize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductBarcode>
 */
class ProductBarcodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_size_id' => ProductSize::factory(),
            'barcode' => $this->faker->unique()->ean13(),
        ];
    }
}
