<?php

use App\Jobs\SyncShopOrders;
use App\Models\Shop;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('it queues a sync job for every active shop', function () {
    Queue::fake();

    $first = Shop::factory()->create();
    $second = Shop::factory()->create();
    $disabled = Shop::factory()->create(['is_active' => false]);

    $this->artisan('app:sync-orders')
        ->expectsOutput('Queued orders sync for 2 shop(s).')
        ->assertSuccessful();

    Queue::assertPushed(SyncShopOrders::class, 2);
    Queue::assertPushed(SyncShopOrders::class, function (SyncShopOrders $job) use ($first) {
        return $job->shop->is($first);
    });
    Queue::assertPushed(SyncShopOrders::class, function (SyncShopOrders $job) use ($second) {
        return $job->shop->is($second);
    });
    Queue::assertNotPushed(SyncShopOrders::class, function (SyncShopOrders $job) use ($disabled) {
        return $job->shop->is($disabled);
    });
});

test('it does nothing without active shops', function () {
    Queue::fake();

    $this->artisan('app:sync-orders')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('sync is scheduled every five minutes without overlapping', function () {
    $events = [];

    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains($event->command, 'app:sync-orders')) {
            $events[] = $event;
        }
    }

    expect($events)->toHaveCount(1);

    /** @var Event $event */
    $event = $events[0];

    expect($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
