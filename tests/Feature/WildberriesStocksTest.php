<?php

use App\Exceptions\Wildberries\RateLimitExceededException;
use App\Exceptions\Wildberries\StocksRejectedException;
use App\Services\Wildberries\Data\StockItemDto;
use App\Services\Wildberries\WildberriesClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

function wbStocksClient(): WildberriesClient
{
    return new WildberriesClient('shop-token', 'https://wb.test', 'https://content.test');
}

test('stocks are sent with put to the warehouse url', function () {
    Http::fake(['wb.test/api/v3/stocks/*' => Http::response(null, 204)]);

    wbStocksClient()->updateStocks(658434, [
        new StockItemDto(chrtId: 12345678, amount: 11),
        new StockItemDto(chrtId: 12345679, amount: 0),
    ]);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'PUT'
            && $request->url() === 'https://wb.test/api/v3/stocks/658434'
            && $request->hasHeader('Authorization', 'shop-token')
            && $request->data() === ['stocks' => [
                ['chrtId' => 12345678, 'amount' => 11],
                ['chrtId' => 12345679, 'amount' => 0],
            ]];
    });
});

test('empty list is rejected before any request', function () {
    Http::fake();

    expect(function () {
        wbStocksClient()->updateStocks(658434, []);
    })->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('more than 1000 stocks are rejected before any request', function () {
    Http::fake();

    $items = [];
    for ($i = 1; $i <= WildberriesClient::MAX_STOCKS_PER_REQUEST + 1; $i++) {
        $items[] = new StockItemDto(chrtId: $i, amount: 1);
    }

    expect(function () use ($items) {
        wbStocksClient()->updateStocks(658434, $items);
    })->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('exactly 1000 stocks are allowed', function () {
    Http::fake(['wb.test/*' => Http::response(null, 204)]);

    $items = [];
    for ($i = 1; $i <= WildberriesClient::MAX_STOCKS_PER_REQUEST; $i++) {
        $items[] = new StockItemDto(chrtId: $i, amount: 1);
    }

    wbStocksClient()->updateStocks(658434, $items);

    Http::assertSentCount(1);
});

test('429 on stocks update gives rate limit exception', function () {
    Http::fake(['wb.test/*' => Http::response([], 429, ['X-Ratelimit-Retry' => '4'])]);

    expect(function () {
        wbStocksClient()->updateStocks(658434, [new StockItemDto(chrtId: 1, amount: 1)]);
    })->toThrow(function (RateLimitExceededException $e) {
        expect($e->retryAfter)->toBe(4);
    });
});

test('wildberries error on stocks update throws', function () {
    Http::fake(['wb.test/*' => Http::response(['code' => 'IncorrectRequestBody', 'message' => 'Incorrect required body'], 400)]);

    wbStocksClient()->updateStocks(658434, [new StockItemDto(chrtId: 1, amount: 1)]);
})->throws(StocksRejectedException::class);

test('negative amount is rejected by dto validation', function () {
    StockItemDto::validateAndCreate(['chrtId' => 1, 'amount' => -1]);
})->throws(ValidationException::class);
