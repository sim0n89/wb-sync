<?php

use App\Models\StockUpload;
use App\Models\StockUploadItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function uploadWithItems(int $daysAgo): StockUpload
{
    $upload = StockUpload::factory()->create();
    StockUploadItem::factory()->count(2)->for($upload, 'upload')->create();
    StockUpload::whereKey($upload->id)->update(['created_at' => now()->subDays($daysAgo)]);

    return $upload;
}

test('uploads older than the history period are pruned with their items', function () {
    config(['services.wildberries.history_days' => 30]);

    $old = uploadWithItems(31);
    $recent = uploadWithItems(29);

    $this->artisan('model:prune', ['--model' => [StockUpload::class]])->assertSuccessful();

    expect(StockUpload::pluck('id')->all())->toBe([$recent->id])
        ->and(StockUploadItem::where('stock_upload_id', $old->id)->count())->toBe(0)
        ->and(StockUploadItem::where('stock_upload_id', $recent->id)->count())->toBe(2);
});

test('history period is taken from the config', function () {
    config(['services.wildberries.history_days' => 7]);

    uploadWithItems(8);
    $kept = uploadWithItems(6);

    $this->artisan('model:prune', ['--model' => [StockUpload::class]])->assertSuccessful();

    expect(StockUpload::pluck('id')->all())->toBe([$kept->id]);
});

test('history pruning is scheduled once a day', function () {
    $found = false;

    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains($event->command, 'model:prune') && str_contains($event->command, 'StockUpload')) {
            $found = $event->expression === '0 4 * * *' && $event->withoutOverlapping && $event->onOneServer;
        }
    }

    expect($found)->toBeTrue();
});
