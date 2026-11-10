<?php

use App\Jobs\SyncShopOrders;
use App\Models\Order;
use App\Models\Shop;
use App\Services\FakeWildberries\FakeOrderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeWbGet(string $token, int $next, int $limit = 1000)
{
    return test()->getJson("/fake-wb/api/v3/orders?next={$next}&limit={$limit}", ['Authorization' => $token]);
}

test('request without token is rejected', function () {
    $this->getJson('/fake-wb/api/v3/orders?next=0&limit=10')
        ->assertStatus(401)
        ->assertJsonPath('title', 'unauthorized');
});

test('invalid pagination is rejected', function () {
    fakeWbGet('token', 0, 5000)->assertStatus(400);
    $this->getJson('/fake-wb/api/v3/orders?limit=10', ['Authorization' => 'token'])->assertStatus(400);
});

test('orders are returned page by page with next cursor', function () {
    app(FakeOrderStore::class)->addOrders('token', 5);
    $first = FakeOrderStore::FIRST_ORDER_ID;

    $page = fakeWbGet('token', 0, 2)->assertOk();
    expect(array_column($page->json('orders'), 'id'))->toBe([$first + 1, $first + 2])
        ->and($page->json('next'))->toBe($first + 2);

    $page = fakeWbGet('token', $first + 4, 2)->assertOk();
    expect(array_column($page->json('orders'), 'id'))->toBe([$first + 5])
        ->and($page->json('next'))->toBe($first + 5);

    $page = fakeWbGet('token', $first + 5, 2)->assertOk();
    expect($page->json('orders'))->toBe([])
        ->and($page->json('next'))->toBe($first + 5);
});

test('orders are separated by token', function () {
    app(FakeOrderStore::class)->addOrders('first', 3);

    expect(fakeWbGet('first', 0)->json('orders'))->toHaveCount(3)
        ->and(fakeWbGet('second', 0)->json('orders'))->toBe([]);
});

test('rate limit answers with 429', function () {
    config(['services.wildberries.fake_rate_limit' => 1]);

    fakeWbGet('token', 0)->assertOk();
    fakeWbGet('token', 0)->assertStatus(429)->assertJsonPath('title', 'too many requests');
});

test('command adds orders', function () {
    $this->artisan('app:fake-wb-add-orders', ['token' => 'token', 'count' => 3])
        ->expectsOutput('Added 3 order(s), 3 in total for this token.')
        ->assertSuccessful();

    $this->artisan('app:fake-wb-add-orders', ['token' => 'token', 'count' => 0])->assertFailed();

    expect(app(FakeOrderStore::class)->getTotal('token'))->toBe(3);
});

test('sync job works against the emulator', function () {
    config(['services.wildberries.marketplace_url' => 'http://fake.test/fake-wb']);
    app(FakeOrderStore::class)->addOrders('shop-token', 2500);

    Http::fake(function (Request $request) {
        $response = $this->call(
            'GET',
            parse_url($request->url(), PHP_URL_PATH),
            $request->data(),
            server: ['HTTP_AUTHORIZATION' => $request->header('Authorization')[0]],
        );

        return Http::response($response->getContent(), $response->getStatusCode());
    });

    $shop = Shop::factory()->create(['wb_token' => 'shop-token']);

    SyncShopOrders::dispatchSync($shop);

    expect(Order::count())->toBe(2500)
        ->and($shop->fresh()->orders_cursor)->toBe(FakeOrderStore::FIRST_ORDER_ID + 2500);

    Http::assertSentCount(4);
});
