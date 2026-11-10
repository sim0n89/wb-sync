<?php

use App\Http\Controllers\FakeWildberries\OrdersController;
use Illuminate\Support\Facades\Route;

Route::get('api/v3/orders', OrdersController::class);
