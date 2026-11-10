<?php

use App\Models\Order;
use App\Models\Shop;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('order belongs to a shop', function () {
    $shop = Shop::factory()->create();
    $order = Order::factory()->for($shop)->create();

    expect($order->shop->is($shop))->toBeTrue()
        ->and($shop->orders()->count())->toBe(1);
});

test('payload is stored as array', function () {
    $order = Order::factory()->create(['payload' => ['id' => 1, 'skus' => ['123']]]);

    expect($order->fresh()->payload)->toBe(['id' => 1, 'skus' => ['123']]);
});

test('same wb order cannot be stored twice for one shop', function () {
    $shop = Shop::factory()->create();
    Order::factory()->for($shop)->create(['wb_order_id' => 13833711]);

    Order::factory()->for($shop)->create(['wb_order_id' => 13833711]);
})->throws(QueryException::class);

test('same wb order id is allowed in different shops', function () {
    Order::factory()->create(['wb_order_id' => 13833711]);
    Order::factory()->create(['wb_order_id' => 13833711]);

    expect(Order::where('wb_order_id', 13833711)->count())->toBe(2);
});

test('orders are deleted together with the shop', function () {
    $shop = Shop::factory()->create();
    Order::factory()->count(2)->for($shop)->create();

    $shop->delete();

    expect(Order::count())->toBe(0);
});
