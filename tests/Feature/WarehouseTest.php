<?php

use App\Models\Shop;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('shop has warehouses', function () {
    $shop = Shop::factory()->create();
    Warehouse::factory()->for($shop)->count(2)->create();

    expect($shop->warehouses()->count())->toBe(2)
        ->and($shop->warehouses()->first()->shop->is($shop))->toBeTrue();
});

test('active scope skips disabled warehouses', function () {
    Warehouse::factory()->create(['name' => 'On']);
    Warehouse::factory()->create(['name' => 'Off', 'is_active' => false]);

    expect(Warehouse::active()->pluck('name')->all())->toBe(['On']);
});

test('new warehouse is active by default', function () {
    $warehouse = new Warehouse;

    expect($warehouse->is_active)->toBeTrue();
});

test('wb warehouse id is unique within a shop', function () {
    $shop = Shop::factory()->create();
    Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 658434]);

    Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 658434]);
})->throws(QueryException::class);

test('same wb warehouse id is allowed in different shops', function () {
    Warehouse::factory()->create(['wb_warehouse_id' => 658434]);
    Warehouse::factory()->create(['wb_warehouse_id' => 658434]);

    expect(Warehouse::where('wb_warehouse_id', 658434)->count())->toBe(2);
});
