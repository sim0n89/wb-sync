<?php

use App\Exceptions\Wildberries\RateLimitExceededException;
use App\Jobs\SyncShopOrders;
use App\Models\Order;
use App\Models\Shop;
use App\Services\Wildberries\WildberriesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);
});

test('429 is turned into exception with retry delay from header', function () {
    Http::fake([
        'wb.test/*' => Http::response(['title' => 'too many requests'], 429, ['X-Ratelimit-Retry' => '7']),
    ]);

    try {
        (new WildberriesClient('token', 'https://wb.test'))->getOrders();
    } catch (RateLimitExceededException $e) {
        expect($e->retryAfter)->toBe(7);

        return;
    }

    $this->fail('RateLimitExceededException was not thrown.');
});

test('429 without header uses default delay', function () {
    Http::fake(['wb.test/*' => Http::response([], 429)]);

    expect(function () {
        (new WildberriesClient('token', 'https://wb.test'))->getOrders();
    })->toThrow(function (RateLimitExceededException $e) {
        expect($e->retryAfter)->toBe(RateLimitExceededException::DEFAULT_RETRY_AFTER);
    });
});

test('client waits instead of exceeding its own request rate', function () {
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();

    Http::fake(['wb.test/*' => Http::response(['next' => 0, 'orders' => []])]);

    $client = new WildberriesClient('token', 'https://wb.test', requestsPerSecond: 2);

    $client->getOrders();
    $client->getOrders();
    Sleep::assertNeverSlept();

    $client->getOrders();
    Sleep::assertSleptTimes(1);
    Http::assertSentCount(3);
});

test('job is released with wildberries delay and keeps loaded pages', function () {
    Http::fake([
        'wb.test/*' => Http::sequence()
            ->push(['next' => 2, 'orders' => [
                ['id' => 1, 'price' => 100, 'createdAt' => '2026-11-01T10:00:00Z'],
                ['id' => 2, 'price' => 100, 'createdAt' => '2026-11-01T10:00:00Z'],
            ]])
            ->push([], 429, ['X-Ratelimit-Retry' => '3']),
    ]);

    $shop = Shop::factory()->create();

    $job = (new SyncShopOrders($shop))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(delay: 3);
    expect(Order::count())->toBe(2)
        ->and($shop->fresh()->orders_cursor)->toBe(2)
        ->and($shop->fresh()->orders_synced_at)->toBeNull();
});

test('emulator sends wildberries rate limit headers', function () {
    config(['services.wildberries.fake_rate_limit' => 1]);

    $this->getJson('/fake-wb/api/v3/orders?next=0&limit=10', ['Authorization' => 'token'])->assertOk();
    $this->getJson('/fake-wb/api/v3/orders?next=0&limit=10', ['Authorization' => 'token'])
        ->assertStatus(429)
        ->assertHeader('X-Ratelimit-Retry')
        ->assertHeader('X-Ratelimit-Limit', '1');
});
