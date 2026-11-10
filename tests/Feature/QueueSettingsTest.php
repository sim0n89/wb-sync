<?php

use App\Jobs\PushShopStocks;
use App\Jobs\SyncShopOrders;
use App\Models\Shop;

dataset('shop jobs', [
    SyncShopOrders::class,
    PushShopStocks::class,
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

test('unique lock outlives all retry pauses', function (string $jobClass) {
    $job = new $jobClass(new Shop);

    expect($job->uniqueFor)->toBeGreaterThan(array_sum($job->backoff()) + $job->timeout * $job->maxExceptions);
})->with('shop jobs');
