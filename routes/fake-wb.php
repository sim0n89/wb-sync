<?php

use App\Http\Controllers\FakeWildberries\OrdersController;
use App\Http\Controllers\FakeWildberries\StocksController;
use App\Http\Controllers\FakeWildberries\StocksReadController;
use Illuminate\Support\Facades\Route;

Route::get('api/v3/orders', OrdersController::class);
Route::put('api/v3/stocks/{warehouseId}', StocksController::class)->whereNumber('warehouseId');
Route::post('api/v3/stocks/{warehouseId}', StocksReadController::class)->whereNumber('warehouseId');
