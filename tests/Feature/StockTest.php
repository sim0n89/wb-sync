<?php

use App\Models\ProductSize;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('stock links a size and a warehouse', function () {
    $warehouse = Warehouse::factory()->create();
    $size = ProductSize::factory()->create();

    $stock = Stock::factory()->for($warehouse)->for($size, 'size')->create(['amount' => 11]);

    expect($stock->warehouse->is($warehouse))->toBeTrue()
        ->and($stock->size->is($size))->toBeTrue()
        ->and($warehouse->stocks()->count())->toBe(1)
        ->and($size->stocks()->first()->amount)->toBe(11);
});

test('one size has one stock row per warehouse', function () {
    $warehouse = Warehouse::factory()->create();
    $size = ProductSize::factory()->create();
    Stock::factory()->for($warehouse)->for($size, 'size')->create();

    Stock::factory()->for($warehouse)->for($size, 'size')->create();
})->throws(QueryException::class);

test('same size may have stock on different warehouses', function () {
    $size = ProductSize::factory()->create();
    Stock::factory()->for($size, 'size')->create();
    Stock::factory()->for($size, 'size')->create();

    expect($size->stocks()->count())->toBe(2);
});

test('new stock starts with zero amount and is not synced', function () {
    $stock = new Stock;

    expect($stock->amount)->toBe(0)
        ->and($stock->synced_amount)->toBeNull();
});

test('needs sync returns never synced and changed stocks only', function () {
    $neverSynced = Stock::factory()->create(['amount' => 5]);
    $changed = Stock::factory()->create(['amount' => 7, 'synced_amount' => 3, 'synced_at' => now()]);
    Stock::factory()->synced()->create(['amount' => 9]);
    $zeroedOut = Stock::factory()->create(['amount' => 0, 'synced_amount' => 4, 'synced_at' => now()]);

    expect(Stock::needsSync()->orderBy('id')->pluck('id')->all())
        ->toBe([$neverSynced->id, $changed->id, $zeroedOut->id]);
});

test('needs sync can be combined with other conditions', function () {
    $warehouse = Warehouse::factory()->create();
    $mine = Stock::factory()->for($warehouse)->create(['synced_amount' => null]);
    Stock::factory()->create(['synced_amount' => null]);

    expect($warehouse->stocks()->needsSync()->pluck('id')->all())->toBe([$mine->id]);
});

test('deleting a warehouse removes its stocks', function () {
    $warehouse = Warehouse::factory()->create();
    Stock::factory()->for($warehouse)->count(2)->create();

    $warehouse->delete();

    expect(Stock::count())->toBe(0);
});
