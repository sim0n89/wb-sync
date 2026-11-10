<?php

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('wb token is stored encrypted', function () {
    $shop = Shop::factory()->create(['wb_token' => 'plain-token']);

    $raw = DB::table('shops')->where('id', $shop->id)->value('wb_token');

    expect($raw)->not->toBe('plain-token')
        ->and(decrypt($raw, false))->toBe('plain-token')
        ->and($shop->fresh()->wb_token)->toBe('plain-token');
});

test('wb token is hidden from serialization', function () {
    $shop = Shop::factory()->create();

    expect($shop->toArray())->not->toHaveKey('wb_token');
});

test('active scope skips disabled shops', function () {
    Shop::factory()->create(['name' => 'On']);
    Shop::factory()->create(['name' => 'Off', 'is_active' => false]);

    expect(Shop::active()->pluck('name')->all())->toBe(['On']);
});

test('command creates a shop with the entered token', function () {
    $this->artisan('app:create-shop', ['name' => 'Main'])
        ->expectsQuestion('Wildberries API token', 'secret-token')
        ->assertSuccessful();

    expect(Shop::where('name', 'Main')->first()->wb_token)->toBe('secret-token');
});

test('command fails without a token', function () {
    $this->artisan('app:create-shop', ['name' => 'Main'])
        ->expectsQuestion('Wildberries API token', '')
        ->assertFailed();

    expect(Shop::count())->toBe(0);
});
