<?php

use App\Jobs\SyncShopOrders;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);
});

test('second job for the same shop is not queued while the first one is pending', function () {
    Queue::fake();

    $shop = Shop::factory()->create();

    SyncShopOrders::dispatch($shop);
    SyncShopOrders::dispatch($shop);

    Queue::assertPushed(SyncShopOrders::class, 1);
});

test('jobs for different shops are queued independently', function () {
    Queue::fake();

    SyncShopOrders::dispatch(Shop::factory()->create());
    SyncShopOrders::dispatch(Shop::factory()->create());

    Queue::assertPushed(SyncShopOrders::class, 2);
});

test('shop can be synced again after the previous job finished', function () {
    Http::fake(['wb.test/*' => Http::response(['next' => 0, 'orders' => []])]);

    $shop = Shop::factory()->create();

    SyncShopOrders::dispatch($shop);
    SyncShopOrders::dispatch($shop);

    Http::assertSentCount(2);
});

test('job is skipped while another job of the same shop is running', function () {
    Http::fake();

    $shop = Shop::factory()->create();
    $job = new SyncShopOrders($shop);
    $overlap = $job->middleware()[0];

    $lock = Cache::lock($overlap->getLockKey($job), 60);
    $lock->get();

    SyncShopOrders::dispatchSync($shop);

    Http::assertNothingSent();
    $lock->release();
});
