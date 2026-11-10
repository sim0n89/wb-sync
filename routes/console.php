<?php

use App\Models\StockUpload;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:sync-orders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:push-stocks')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:verify-stocks')
    ->cron('3-59/10 * * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('model:prune', ['--model' => [StockUpload::class]])
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();
