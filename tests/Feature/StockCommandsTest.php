<?php

use App\Jobs\PushShopStocks;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\FakeWildberries\FakeStockStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('demo catalog creates warehouses, products, sizes, barcodes and stocks', function () {
    $shop = Shop::factory()->create();

    $this->artisan('app:demo-catalog', ['shop' => $shop->id, '--products' => 2])
        ->expectsOutput('Created 2 product(s), 6 size(s), stocks on 2 warehouse(s).')
        ->assertSuccessful();

    expect($shop->warehouses()->pluck('wb_warehouse_id')->sort()->values()->all())->toBe([507, 508])
        ->and($shop->products()->count())->toBe(2)
        ->and(ProductSize::count())->toBe(6)
        ->and(ProductSize::has('barcodes')->count())->toBe(6)
        ->and(Stock::count())->toBe(12);
});

test('demo catalog can be run twice for the same shop', function () {
    $shop = Shop::factory()->create();

    $this->artisan('app:demo-catalog', ['shop' => $shop->id, '--products' => 1])->assertSuccessful();
    $this->artisan('app:demo-catalog', ['shop' => $shop->id, '--products' => 1])->assertSuccessful();

    expect($shop->warehouses()->count())->toBe(2)
        ->and($shop->products()->count())->toBe(2);
});

test('set stock changes the amount by chrt id and warehouse', function () {
    $warehouse = Warehouse::factory()->create(['wb_warehouse_id' => 507]);
    $size = ProductSize::factory()->for(Product::factory()->for($warehouse->shop))->create(['chrt_id' => 40000001]);
    $stock = Stock::factory()->for($warehouse)->for($size, 'size')->create(['amount' => 5]);

    $this->artisan('app:set-stock', ['chrt_id' => 40000001, 'wb_warehouse_id' => 507, 'amount' => 9])->assertSuccessful();
    $this->artisan('app:set-stock', ['chrt_id' => 40000001, 'wb_warehouse_id' => 999, 'amount' => 9])->assertFailed();
    $this->artisan('app:set-stock', ['chrt_id' => 40000001, 'wb_warehouse_id' => 507, 'amount' => -1])->assertFailed();

    expect($stock->fresh()->amount)->toBe(9);
});

test('push stocks command queues a job for every active shop', function () {
    Queue::fake();

    Shop::factory()->count(2)->create();
    Shop::factory()->create(['is_active' => false]);

    $this->artisan('app:push-stocks')
        ->expectsOutput('Queued stocks push for 2 shop(s).')
        ->assertSuccessful();

    Queue::assertPushed(PushShopStocks::class, 2);
});

test('stocks push is scheduled every five minutes', function () {
    $found = false;

    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains($event->command, 'app:push-stocks')) {
            $found = $event->expression === '*/5 * * * *' && $event->withoutOverlapping && $event->onOneServer;
        }
    }

    expect($found)->toBeTrue();
});

test('emulator commands switch rejections and blocks', function () {
    $store = app(FakeStockStore::class);

    $this->artisan('app:fake-wb-reject-chrt', ['token' => 't', 'chrt_id' => 5, '--hide-ids' => true])->assertSuccessful();
    expect($store->getRejections('t'))->toBe([5 => ['code' => 'TnvedRequired', 'list' => false]]);

    $this->artisan('app:fake-wb-reject-chrt', ['token' => 't', 'chrt_id' => 5, '--off' => true])->assertSuccessful();
    expect($store->getRejections('t'))->toBe([]);

    $this->artisan('app:fake-wb-block-warehouse', ['token' => 't', 'wb_warehouse_id' => 507])->assertSuccessful();
    expect($store->isWarehouseBlocked('t', 507))->toBeTrue();

    $this->artisan('app:fake-wb-block-warehouse', ['token' => 't', 'wb_warehouse_id' => 507, '--off' => true])->assertSuccessful();
    expect($store->isWarehouseBlocked('t', 507))->toBeFalse();

    $store->saveStocks('t', 507, [1 => 3]);
    $this->artisan('app:fake-wb-stocks', ['token' => 't', 'wb_warehouse_id' => 507])
        ->expectsTable(['chrtId', 'amount'], [[1, 3]])
        ->assertSuccessful();
});
