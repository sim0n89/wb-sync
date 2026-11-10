<?php

use App\Models\Shop;
use App\Services\Wildberries\Data\OrderDto;
use App\Services\Wildberries\WildberriesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);
});

test('it requests orders with shop token and pagination', function () {
    $wbOrder = [
        'id' => 13833711,
        'rid' => 'f884001e44e511edb8780242ac120002',
        'article' => 'one-ring-7548',
        'nmId' => 12345678,
        'chrtId' => 987654321,
        'warehouseId' => 658434,
        'price' => 1014,
        'currencyCode' => 933,
        'createdAt' => '2022-05-04T07:56:29Z',
        'skus' => ['6665956397512'],
    ];

    Http::fake([
        'wb.test/api/v3/orders*' => Http::response(['next' => 13833711, 'orders' => [$wbOrder]]),
    ]);

    $shop = Shop::factory()->create(['wb_token' => 'shop-token']);

    $page = WildberriesClient::forShop($shop)->getOrders(next: 500, limit: 100);
    $order = $page->orders[0];

    expect($page->next)->toBe(13833711)
        ->and($page->isEmpty())->toBeFalse()
        ->and($order)->toBeInstanceOf(OrderDto::class)
        ->and($order->id)->toBe(13833711)
        ->and($order->nmId)->toBe(12345678)
        ->and($order->warehouseId)->toBe(658434)
        ->and($order->price)->toBe(1014)
        ->and($order->createdAt->utc()->toDateTimeString())->toBe('2022-05-04 07:56:29')
        ->and($order->payload)->toBe($wbOrder);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'shop-token')
            && $request['next'] === 500
            && $request['limit'] === 100;
    });
});

test('it passes the period as unix timestamps', function () {
    Http::fake(['wb.test/*' => Http::response(['next' => 0, 'orders' => []])]);

    $from = Carbon::parse('2026-11-01 00:00:00', 'UTC');
    $to = Carbon::parse('2026-11-10 00:00:00', 'UTC');

    (new WildberriesClient('token', 'https://wb.test'))->getOrders(from: $from, to: $to);

    Http::assertSent(function (Request $request) use ($from, $to) {
        return $request['dateFrom'] === $from->getTimestamp()
            && $request['dateTo'] === $to->getTimestamp();
    });
});

test('limit is capped by wildberries maximum', function () {
    Http::fake(['wb.test/*' => Http::response(['next' => 0, 'orders' => []])]);

    (new WildberriesClient('token', 'https://wb.test'))->getOrders(limit: 5000);

    Http::assertSent(function (Request $request) {
        return $request['limit'] === WildberriesClient::MAX_LIMIT;
    });
});

test('empty response gives an empty page', function () {
    Http::fake(['wb.test/*' => Http::response(['next' => 0, 'orders' => []])]);

    $page = (new WildberriesClient('token', 'https://wb.test'))->getOrders();

    expect($page->isEmpty())->toBeTrue()
        ->and($page->next)->toBe(0);
});

test('error response throws', function () {
    Http::fake(['wb.test/*' => Http::response(['title' => 'unauthorized'], 401)]);

    (new WildberriesClient('bad-token', 'https://wb.test'))->getOrders();
})->throws(RequestException::class);
