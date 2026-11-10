<?php

namespace Database\Factories;

use App\Models\StockUpload;
use App\Models\StockUploadItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockUploadItem>
 */
class StockUploadItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_upload_id' => StockUpload::factory(),
            'stock_id' => null,
            'chrt_id' => $this->faker->numberBetween(10_000_000, 99_999_999),
            'amount' => $this->faker->numberBetween(0, 100),
        ];
    }
}
