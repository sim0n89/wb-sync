<?php

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductSize;
use App\Models\Shop;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product has sizes and sizes have barcodes', function () {
    $shop = Shop::factory()->create();
    $product = Product::factory()->for($shop)->create();
    $size = ProductSize::factory()->for($product)->create(['tech_size' => 'M']);
    ProductBarcode::factory()->for($size, 'size')->count(2)->create();

    expect($shop->products()->count())->toBe(1)
        ->and($product->sizes()->pluck('tech_size')->all())->toBe(['M'])
        ->and($size->barcodes()->count())->toBe(2)
        ->and($size->barcodes()->first()->size->product->is($product))->toBeTrue();
});

test('size can be found by any of its barcodes', function () {
    $size = ProductSize::factory()->create();
    ProductBarcode::factory()->for($size, 'size')->create(['barcode' => '4600000000001']);
    ProductBarcode::factory()->for($size, 'size')->create(['barcode' => '4600000000002']);

    $found = ProductSize::whereRelation('barcodes', 'barcode', '4600000000002')->first();

    expect($found->is($size))->toBeTrue();
});

test('nm id is unique within a shop', function () {
    $shop = Shop::factory()->create();
    Product::factory()->for($shop)->create(['nm_id' => 370870300]);

    Product::factory()->for($shop)->create(['nm_id' => 370870300]);
})->throws(QueryException::class);

test('vendor code is unique within a shop but may repeat in another shop', function () {
    Product::factory()->create(['vendor_code' => 'wv1702fyjh']);
    Product::factory()->create(['vendor_code' => 'wv1702fyjh']);

    expect(Product::where('vendor_code', 'wv1702fyjh')->count())->toBe(2);

    $shop = Shop::factory()->create();
    Product::factory()->for($shop)->create(['vendor_code' => 'same']);

    expect(function () use ($shop) {
        Product::factory()->for($shop)->create(['vendor_code' => 'same']);
    })->toThrow(QueryException::class);
});

test('chrt id is unique', function () {
    ProductSize::factory()->create(['chrt_id' => 12345678]);

    ProductSize::factory()->create(['chrt_id' => 12345678]);
})->throws(QueryException::class);

test('same barcode cannot be added twice to one size', function () {
    $size = ProductSize::factory()->create();
    ProductBarcode::factory()->for($size, 'size')->create(['barcode' => '4600000000001']);

    ProductBarcode::factory()->for($size, 'size')->create(['barcode' => '4600000000001']);
})->throws(QueryException::class);

test('deleting a product removes its sizes and barcodes', function () {
    $product = Product::factory()->create();
    $size = ProductSize::factory()->for($product)->create();
    ProductBarcode::factory()->for($size, 'size')->create();

    $product->delete();

    expect(ProductSize::count())->toBe(0)
        ->and(ProductBarcode::count())->toBe(0);
});
