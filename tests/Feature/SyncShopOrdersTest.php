<?php

use App\Jobs\SyncShopOrders;
use App\Models\Order;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);
});

function wbOrder(int $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'rid' => "rid-{$id}",
        'article' => "art-{$id}",
        'nmId' => 1000 + $id,
        'chrtId' => 2000 + $id,
        'warehouseId' => 658434,
        'price' => 1014,
        'currencyCode' => 643,
        'createdAt' => '2026-11-01T10:00:00Z',
        ...$overrides,
    ];
}

function wbPage(int $next, array $orders): array
{
    return ['next' => $next, 'orders' => $orders];
}

test('it walks all pages and stores orders', function () {
    Http::fake([
        'wb.test/*' => Http::sequence()
            ->push(wbPage(2, [wbOrder(1), wbOrder(2)]))
            ->push(wbPage(3, [wbOrder(3)]))
            ->push(wbPage(3, [])),
    ]);

    $shop = Shop::factory()->create();

    SyncShopOrders::dispatchSync($shop);

    $order = Order::where('wb_order_id', 3)->first();

    expect(Order::count())->toBe(3)
        ->and($order->shop_id)->toBe($shop->id)
        ->and($order->nm_id)->toBe(1003)
        ->and($order->wb_created_at->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and($order->payload['rid'])->toBe('rid-3')
        ->and($shop->fresh()->orders_cursor)->toBe(3)
        ->and($shop->fresh()->orders_synced_at)->not->toBeNull();

    $cursors = [];
    Http::assertSent(function (Request $request) use (&$cursors) {
        $cursors[] = $request['next'];

        return true;
    });

    expect($cursors)->toBe([0, 2, 3]);
});

test('it continues from the saved cursor', function () {
    Http::fake(['wb.test/*' => Http::response(wbPage(500, []))]);

    $shop = Shop::factory()->create(['orders_cursor' => 500]);

    SyncShopOrders::dispatchSync($shop);

    Http::assertSent(function (Request $request) {
        return $request['next'] === 500;
    });
});

test('running twice does not duplicate orders and updates changed data', function () {
    Http::fake([
        'wb.test/*' => Http::sequence()
            ->push(wbPage(1, [wbOrder(1)]))
            ->push(wbPage(1, []))
            ->push(wbPage(1, [wbOrder(1, ['price' => 2000])]))
            ->push(wbPage(1, [])),
    ]);

    $shop = Shop::factory()->create();

    SyncShopOrders::dispatchSync($shop);
    SyncShopOrders::dispatchSync($shop->fresh());

    expect(Order::count())->toBe(1)
        ->and(Order::first()->price)->toBe(2000);
});

test('it stops when wildberries returns the same cursor', function () {
    Http::fake(['wb.test/*' => Http::response(wbPage(7, [wbOrder(7)]))]);

    $shop = Shop::factory()->create(['orders_cursor' => 7]);

    SyncShopOrders::dispatchSync($shop);

    Http::assertSentCount(1);
    expect(Order::count())->toBe(1);
});

test('inactive shop is skipped', function () {
    Http::fake();

    $shop = Shop::factory()->create(['is_active' => false]);

    SyncShopOrders::dispatchSync($shop);

    Http::assertNothingSent();
});

test('pages loaded before an error are kept', function () {
    Http::fake([
        'wb.test/*' => Http::sequence()
            ->push(wbPage(2, [wbOrder(1), wbOrder(2)]))
            ->push(['title' => 'internal error'], 500),
    ]);

    $shop = Shop::factory()->create();

    expect(function () use ($shop) {
        SyncShopOrders::dispatchSync($shop);
    })->toThrow(RequestException::class);

    expect(Order::count())->toBe(2)
        ->and($shop->fresh()->orders_cursor)->toBe(2)
        ->and($shop->fresh()->orders_synced_at)->toBeNull();
});
