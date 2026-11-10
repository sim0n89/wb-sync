<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $wbOrderId = $this->faker->unique()->numberBetween(10_000_000, 99_999_999);
        $createdAt = $this->faker->dateTimeBetween('-30 days');

        return [
            'shop_id' => Shop::factory(),
            'wb_order_id' => $wbOrderId,
            'rid' => $this->faker->md5(),
            'article' => $this->faker->bothify('art-####'),
            'nm_id' => $this->faker->numberBetween(1_000_000, 9_999_999),
            'chrt_id' => $this->faker->numberBetween(1_000_000, 9_999_999),
            'warehouse_id' => $this->faker->numberBetween(100_000, 999_999),
            'price' => $this->faker->numberBetween(10_000, 1_000_000),
            'currency_code' => 643,
            'wb_created_at' => $createdAt,
            'payload' => ['id' => $wbOrderId],
        ];
    }
}
