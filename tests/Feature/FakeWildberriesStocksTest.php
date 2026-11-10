<?php

use App\Jobs\PushShopStocks;
use App\Models\Shop;
use App\Models\Stock;
use App\Services\FakeWildberries\FakeStockStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

function fakeWbPut(string $token, int $warehouseId, mixed $body)
{
    return test()->putJson("/fake-wb/api/v3/stocks/{$warehouseId}", $body, ['Authorization' => $token]);
}

test('stocks are saved by the emulator per token and warehouse', function () {
    fakeWbPut('token', 507, ['stocks' => [['chrtId' => 2, 'amount' => 5], ['chrtId' => 1, 'amount' => 0]]])->assertNoContent();

    $store = app(FakeStockStore::class);

    expect($store->getStocks('token', 507))->toBe([1 => 0, 2 => 5])
        ->and($store->getStocks('token', 508))->toBe([])
        ->and($store->getStocks('other', 507))->toBe([]);
});

test('wrong field names get 204 but nothing is saved like in wildberries', function () {
    fakeWbPut('token', 507, ['stocks' => [['sku' => '4600000000001', 'amount' => 5]]])->assertNoContent();

    expect(app(FakeStockStore::class)->getStocks('token', 507))->toBe([]);
});

test('missing body or token is rejected', function () {
    fakeWbPut('token', 507, ['items' => []])->assertStatus(400)->assertJsonPath('0.code', 'IncorrectRequestBody');
    $this->putJson('/fake-wb/api/v3/stocks/507', ['stocks' => [['chrtId' => 1, 'amount' => 1]]])->assertStatus(401);
});

test('rejected chrt id gets 409 with or without data', function () {
    $store = app(FakeStockStore::class);
    $store->rejectChrt('token', 1, 'TnvedRequired', true);
    $store->rejectChrt('token', 2, 'NotFound', false);

    fakeWbPut('token', 507, ['stocks' => [['chrtId' => 1, 'amount' => 5], ['chrtId' => 2, 'amount' => 3], ['chrtId' => 3, 'amount' => 1]]])
        ->assertStatus(409)
        ->assertJsonPath('0.code', 'TnvedRequired')
        ->assertJsonPath('0.data', [['chrtId' => 1, 'amount' => 5]])
        ->assertJsonPath('1.code', 'NotFound')
        ->assertJsonPath('1.data', []);

    expect($store->getStocks('token', 507))->toBe([]);

    $store->acceptChrt('token', 1);
    $store->acceptChrt('token', 2);

    fakeWbPut('token', 507, ['stocks' => [['chrtId' => 1, 'amount' => 5]]])->assertNoContent();
});

test('blocked warehouse gets 406', function () {
    $store = app(FakeStockStore::class);
    $store->setWarehouseBlocked('token', 507, true);

    fakeWbPut('token', 507, ['stocks' => [['chrtId' => 1, 'amount' => 5]]])
        ->assertStatus(406)
        ->assertJsonPath('0.code', 'WarehouseStocksUpdateBlock');

    $store->setWarehouseBlocked('token', 507, false);

    fakeWbPut('token', 507, ['stocks' => [['chrtId' => 1, 'amount' => 5]]])->assertNoContent();
});

test('emulator reads stocks back and can silently drop a size', function () {
    $store = app(FakeStockStore::class);
    $store->setChrtDropped('token', 2, true);

    fakeWbPut('token', 507, ['stocks' => [['chrtId' => 1, 'amount' => 5], ['chrtId' => 2, 'amount' => 7]]])->assertNoContent();

    $this->postJson('/fake-wb/api/v3/stocks/507', ['chrtIds' => [1, 2]], ['Authorization' => 'token'])
        ->assertOk()
        ->assertExactJson(['stocks' => [['chrtId' => 1, 'amount' => 5]]]);

    $this->postJson('/fake-wb/api/v3/stocks/507', ['chrtIds' => []], ['Authorization' => 'token'])->assertStatus(400);
});

test('push job works against the emulator including hidden rejection', function () {
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();
    config(['services.wildberries.marketplace_url' => 'http://fake.test/fake-wb']);

    Http::fake(function (Request $request) {
        $response = $this->call(
            $request->method(),
            parse_url($request->url(), PHP_URL_PATH),
            server: ['HTTP_AUTHORIZATION' => $request->header('Authorization')[0], 'CONTENT_TYPE' => 'application/json'],
            content: $request->body(),
        );

        return Http::response($response->getContent(), $response->getStatusCode());
    });

    $shop = Shop::factory()->create(['wb_token' => 'demo-token']);
    $this->artisan('app:demo-catalog', ['shop' => $shop->id, '--products' => 2])->assertSuccessful();

    $badChrtId = Stock::with('size')->first()->size->chrt_id;
    app(FakeStockStore::class)->rejectChrt('demo-token', $badChrtId, 'TnvedRequired', false);

    PushShopStocks::dispatchSync($shop);

    $emulated = app(FakeStockStore::class)->getStocks('demo-token', 507);

    expect($emulated)->toHaveCount(5)
        ->and($emulated)->not->toHaveKey($badChrtId)
        ->and(Stock::failed()->count())->toBe(2)
        ->and(Stock::needsSync()->count())->toBe(0);
});
