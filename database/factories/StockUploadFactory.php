<?php

namespace Database\Factories;

use App\Enums\StockUploadStatus;
use App\Models\StockUpload;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockUpload>
 */
class StockUploadFactory extends Factory
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
            'shop_id' => function (array $attributes) {
                return Warehouse::find($attributes['warehouse_id'])->shop_id;
            },
            'status' => StockUploadStatus::InProgress,
            'items_count' => 1,
            'response_status' => null,
            'error' => null,
        ];
    }
}
