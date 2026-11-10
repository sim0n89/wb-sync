<?php

use App\Jobs\PushShopStocks;
use App\Jobs\SyncShopOrders;
use App\Jobs\VerifyShopStocks;
use App\Models\Shop;

dataset('shop jobs', [
    SyncShopOrders::class,
    PushShopStocks::class,
    VerifyShopStocks::class,
]);

test('redis retry_after is longer than job timeout', function (string $jobClass) {
    $job = new $jobClass(new Shop);

    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan($job->timeout);
})->with('shop jobs');

test('time budget, job timeout, horizon timeout and retry_after go in increasing order', function (string $jobClass) {
    $job = new $jobClass(new Shop);

    expect($job->timeBudget)->toBeLessThan($job->timeout)
        ->and($job->timeout)->toBeLessThan(config('horizon.defaults.supervisor-1.timeout'))
        ->and(config('horizon.defaults.supervisor-1.timeout'))->toBeLessThan(config('queue.connections.redis.retry_after'));
})->with('shop jobs');

test('horizon environments do not override the supervisor timeout', function () {
    foreach (config('horizon.environments') as $supervisors) {
        foreach ($supervisors as $supervisor) {
            expect($supervisor)->not->toHaveKey('timeout');
        }
    }
});

test('failed attempts are retried with growing pauses', function (string $jobClass) {
    $job = new $jobClass(new Shop);

    expect($job->backoff())->toBe([30, 120, 300])
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->tries)->toBe(20);
})->with('shop jobs');

test('unique lock outlives one run plus the longest pause but does not stall a shop for long', function (string $jobClass) {
    $job = new $jobClass(new Shop);

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout + max($job->backoff()))
        ->and($job->uniqueFor)->toBeLessThanOrEqual(900);
})->with('shop jobs');

test('stocks push and verification share a lock, a busy push waits and a busy verification is skipped', function () {
    $shop = new Shop;
    $shop->id = 7;

    $push = (new PushShopStocks($shop))->middleware()[0];
    $verify = (new VerifyShopStocks($shop))->middleware()[0];

    expect($push->key)->toBe('stocks-7')
        ->and($push->shareKey)->toBeTrue()
        ->and($push->releaseAfter)->toBe(60)
        ->and($verify->key)->toBe('stocks-7')
        ->and($verify->shareKey)->toBeTrue()
        ->and($verify->releaseAfter)->toBeNull();

    $orders = (new SyncShopOrders($shop))->middleware()[0];

    expect($orders->shareKey)->toBeFalse()
        ->and($orders->releaseAfter)->toBeNull();
});
