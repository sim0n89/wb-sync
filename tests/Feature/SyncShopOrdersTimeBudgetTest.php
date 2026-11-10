<?php

use App\Jobs\SyncShopOrders;
use App\Models\Order;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

test('orders sync stops when the time budget is spent and keeps the cursor', function () {
    Http::preventStrayRequests();
    Sleep::fake();
    $this->freezeTime();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);

    $order = function (int $id) {
        return ['id' => $id, 'price' => 100, 'createdAt' => '2026-11-01T10:00:00Z'];
    };

    $pages = [
        ['next' => 1, 'orders' => [$order(1)]],
        ['next' => 2, 'orders' => [$order(2)]],
    ];

    Http::fake(function () use (&$pages) {
        $this->travel(250)->seconds();

        return Http::response(array_shift($pages) ?? ['next' => 2, 'orders' => []]);
    });

    $shop = Shop::factory()->create();

    SyncShopOrders::dispatchSync($shop);

    Http::assertSentCount(1);
    expect(Order::count())->toBe(1)
        ->and($shop->fresh()->orders_cursor)->toBe(1)
        ->and($shop->fresh()->orders_synced_at)->toBeNull();

    SyncShopOrders::dispatchSync($shop->fresh());

    expect(Order::count())->toBe(2)
        ->and($shop->fresh()->orders_cursor)->toBe(2);
});

test('orders are not marked synced when the page limit stops the run early', function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);

    $next = 0;

    Http::fake(function () use (&$next) {
        $next++;

        return Http::response(['next' => $next, 'orders' => [['id' => $next, 'price' => 100, 'createdAt' => '2026-11-01T10:00:00Z']]]);
    });

    $shop = Shop::factory()->create();

    SyncShopOrders::dispatchSync($shop);

    Http::assertSentCount(SyncShopOrders::MAX_PAGES);
    expect($shop->fresh()->orders_synced_at)->toBeNull()
        ->and($shop->fresh()->orders_cursor)->toBe(SyncShopOrders::MAX_PAGES);
});

test('orders synced time is the moment the run started', function () {
    Http::preventStrayRequests();
    Sleep::fake();
    $this->freezeTime();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);

    $startedAt = now()->toDateTimeString();

    Http::fake(function () {
        $this->travel(30)->seconds();

        return Http::response(['next' => 0, 'orders' => []]);
    });

    $shop = Shop::factory()->create();

    SyncShopOrders::dispatchSync($shop);

    expect($shop->fresh()->orders_synced_at->toDateTimeString())->toBe($startedAt);
});
