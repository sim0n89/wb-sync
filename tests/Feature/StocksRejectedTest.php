<?php

use App\Enums\StocksErrorType;
use App\Enums\StockUploadStatus;
use App\Exceptions\Wildberries\StocksRejectedException;
use App\Jobs\PushShopStocks;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\StockUpload;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);
});

function wbRejection(string $code, array $data = []): array
{
    return [[
        'data' => $data,
        'code' => $code,
        'message' => "{$code} message",
    ]];
}

function rejectedStock(Warehouse $warehouse, int $chrtId, int $amount): Stock
{
    $product = Product::factory()->for($warehouse->shop)->create();
    $size = ProductSize::factory()->for($product)->create(['chrt_id' => $chrtId]);

    return Stock::factory()->for($warehouse)->for($size, 'size')->create(['amount' => $amount]);
}

function sentChrtIds(): array
{
    $sent = [];

    Http::assertSent(function (Request $request) use (&$sent) {
        $ids = [];
        foreach ($request->data()['stocks'] as $item) {
            $ids[] = $item['chrtId'];
        }
        $sent[] = $ids;

        return true;
    });

    return $sent;
}

test('wildberries errors are split into three types', function (int $status, string $code, StocksErrorType $type) {
    $response = new Response(new GuzzleHttp\Psr7\Response($status, [], json_encode(wbRejection($code))));

    expect(StocksRejectedException::fromResponse($response)->type)->toBe($type);
})->with([
    'store blocked' => [406, 'StocksUpdateBlock', StocksErrorType::Temporary],
    'warehouse busy' => [406, 'WarehouseStocksUpdateBlock', StocksErrorType::Temporary],
    'store processing' => [409, 'StoreIsProcessing', StocksErrorType::Temporary],
    'upload limit' => [409, 'UploadDataLimit', StocksErrorType::Temporary],
    'size not found' => [409, 'NotFound', StocksErrorType::Item],
    'category restricted' => [409, 'SubjectRestriction', StocksErrorType::Item],
    'hs code missing' => [409, 'TnvedRequired', StocksErrorType::Item],
    'cargo restricted' => [409, 'CargoWarehouseRestrictionSGT', StocksErrorType::Item],
    'bad body' => [400, 'IncorrectRequestBody', StocksErrorType::Request],
    'warehouse not found' => [404, 'NotFound', StocksErrorType::Request],
]);

test('each rejected chrt id keeps the code of its own error group', function () {
    $body = json_encode([
        ['code' => 'NotFound', 'message' => 'Not found', 'data' => [['chrtId' => 101, 'amount' => 1], 102]],
        ['code' => 'TnvedRequired', 'message' => 'Add HS code', 'data' => ['chrtId' => 103, 'amount' => 5]],
    ]);
    $response = new Response(new GuzzleHttp\Psr7\Response(409, [], $body));

    $e = StocksRejectedException::fromResponse($response);

    expect(array_keys($e->itemErrors))->toBe([101, 103])
        ->and($e->itemErrors[101]['code'])->toBe('NotFound')
        ->and($e->itemErrors[103]['code'])->toBe('TnvedRequired');
});

test('temporary code in the first group does not hide item errors in the next one', function () {
    $body = json_encode([
        ['code' => 'UploadDataLimit', 'message' => 'Upload limit exceeded.', 'data' => []],
        ['code' => 'SubjectRestriction', 'message' => 'Category restricted', 'data' => [['chrtId' => 101]]],
    ]);
    $response = new Response(new GuzzleHttp\Psr7\Response(409, [], $body));

    $e = StocksRejectedException::fromResponse($response);

    expect($e->type)->toBe(StocksErrorType::Item)
        ->and($e->errorCode)->toBe('SubjectRestriction')
        ->and(array_keys($e->itemErrors))->toBe([101]);
});

test('different item errors are saved on the right stocks', function () {
    $shop = Shop::factory()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    $notFound = rejectedStock($warehouse, 101, 5);
    $noHsCode = rejectedStock($warehouse, 102, 5);
    $good = rejectedStock($warehouse, 103, 5);

    Http::fake(['wb.test/*' => Http::sequence()
        ->push([
            ['code' => 'NotFound', 'message' => 'Not found', 'data' => [['chrtId' => 101]]],
            ['code' => 'TnvedRequired', 'message' => 'Add HS code', 'data' => [['chrtId' => 102]]],
        ], 409)
        ->push(null, 204),
    ]);

    PushShopStocks::dispatchSync($shop);

    expect($notFound->fresh()->failed_code)->toBe('NotFound')
        ->and($noHsCode->fresh()->failed_code)->toBe('TnvedRequired')
        ->and($good->fresh()->synced_amount)->toBe(5);
});

test('splitting a batch is capped and untried stocks are not blamed', function () {
    $shop = Shop::factory()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    $product = Product::factory()->for($shop)->create();
    $sizes = ProductSize::factory()->for($product)->count(200)->create();

    $rows = [];
    foreach ($sizes as $size) {
        $rows[] = ['warehouse_id' => $warehouse->id, 'product_size_id' => $size->id, 'amount' => 1];
    }
    Stock::insert($rows);

    Http::fake(['wb.test/*' => Http::response(wbRejection('SubjectRestriction'), 409)]);

    PushShopStocks::dispatchSync($shop);

    $failed = Stock::failed()->count();

    Http::assertSentCount(PushShopStocks::MAX_SPLIT_REQUESTS + 1);
    expect($failed)->toBeGreaterThan(0)->toBeLessThan(200)
        ->and(Stock::needsSync()->count())->toBe(200 - $failed)
        ->and(Stock::whereNotNull('synced_amount')->count())->toBe(0);
});

test('splitting stops when the time budget is spent', function () {
    $this->freezeTime();

    $shop = Shop::factory()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    foreach ([101, 102, 103, 104] as $chrtId) {
        rejectedStock($warehouse, $chrtId, 1);
    }

    Http::fake(function () {
        $this->travel(250)->seconds();

        return Http::response(wbRejection('TnvedRequired'), 409);
    });

    PushShopStocks::dispatchSync($shop);

    Http::assertSentCount(1);
    expect(Stock::failed()->count())->toBe(0)
        ->and(Stock::needsSync()->count())->toBe(4)
        ->and(StockUpload::where('status', StockUploadStatus::InProgress)->count())->toBe(0);
});

test('single error object without list is understood too', function () {
    $body = json_encode(['code' => 'StoreIsProcessing', 'message' => 'The store is processing']);
    $response = new Response(new GuzzleHttp\Psr7\Response(409, [], $body));

    $e = StocksRejectedException::fromResponse($response);

    expect($e->type)->toBe(StocksErrorType::Temporary)
        ->and($e->errorCode)->toBe('StoreIsProcessing');
});

test('items listed by wildberries are marked failed and the rest is sent again', function () {
    $shop = Shop::factory()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    $bad = rejectedStock($warehouse, 101, 5);
    $good = rejectedStock($warehouse, 102, 7);

    Http::fake(['wb.test/*' => Http::sequence()
        ->push(wbRejection('SubjectRestriction', [['chrtId' => 101]]), 409)
        ->push(null, 204),
    ]);

    PushShopStocks::dispatchSync($shop);

    $bad->refresh();

    expect(sentChrtIds())->toBe([[101, 102], [102]])
        ->and($good->fresh()->synced_amount)->toBe(7)
        ->and($bad->synced_amount)->toBeNull()
        ->and($bad->failed_amount)->toBe(5)
        ->and($bad->failed_code)->toBe('SubjectRestriction')
        ->and(StockUpload::orderBy('id')->pluck('status')->all())->toBe([StockUploadStatus::Failed, StockUploadStatus::Sent]);
});

test('without listed items the batch is halved until the bad item is found', function () {
    $shop = Shop::factory()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    foreach ([101, 102, 103, 104] as $chrtId) {
        rejectedStock($warehouse, $chrtId, 1);
    }

    Http::fake(function (Request $request) {
        foreach ($request->data()['stocks'] as $item) {
            if ($item['chrtId'] === 103) {
                return Http::response(wbRejection('TnvedRequired'), 409);
            }
        }

        return Http::response(null, 204);
    });

    PushShopStocks::dispatchSync($shop);

    expect(sentChrtIds())->toBe([[101, 102, 103, 104], [101, 102], [103, 104], [103], [104]])
        ->and(Stock::whereNotNull('synced_amount')->count())->toBe(3)
        ->and(Stock::failed()->sole()->size->chrt_id)->toBe(103);
});

test('failed item is skipped until its amount changes', function () {
    $shop = Shop::factory()->create();
    $stock = rejectedStock(Warehouse::factory()->for($shop)->create(), 101, 5);
    $stock->update(['failed_amount' => 5, 'failed_at' => now(), 'failed_code' => 'TnvedRequired']);

    Http::fake(['wb.test/*' => Http::response(null, 204)]);

    PushShopStocks::dispatchSync($shop);
    Http::assertNothingSent();

    $stock->update(['amount' => 3]);
    PushShopStocks::dispatchSync($shop);

    $stock->refresh();

    expect(sentChrtIds())->toBe([[101]])
        ->and($stock->synced_amount)->toBe(3)
        ->and($stock->failed_amount)->toBeNull()
        ->and($stock->failed_code)->toBeNull();
});

test('failed item is tried again after a day even with the same amount', function () {
    $shop = Shop::factory()->create();
    $stock = rejectedStock(Warehouse::factory()->for($shop)->create(), 101, 5);
    $stock->update(['failed_amount' => 5, 'failed_at' => now(), 'failed_code' => 'TnvedRequired']);

    Http::fake(['wb.test/*' => Http::response(null, 204)]);

    $this->travel(Stock::FAILED_RETRY_HOURS + 1)->hours();
    PushShopStocks::dispatchSync($shop);

    expect($stock->fresh()->synced_amount)->toBe(5);
});

test('temporary block skips the warehouse without failing the job or marking items', function () {
    $shop = Shop::factory()->create();
    $blocked = rejectedStock(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]), 101, 5);
    $open = rejectedStock(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]), 102, 7);

    Http::fake([
        'wb.test/api/v3/stocks/1' => Http::response(wbRejection('WarehouseStocksUpdateBlock'), 406),
        'wb.test/api/v3/stocks/2' => Http::response(null, 204),
    ]);

    PushShopStocks::dispatchSync($shop);

    $blocked->refresh();
    $failedUpload = StockUpload::where('status', StockUploadStatus::Failed)->sole();

    expect($open->fresh()->synced_amount)->toBe(7)
        ->and($blocked->synced_amount)->toBeNull()
        ->and($blocked->failed_amount)->toBeNull()
        ->and($failedUpload->response_status)->toBe(406)
        ->and($failedUpload->error)->toContain('WarehouseStocksUpdateBlock');
});

test('reset command clears errors of one shop only', function () {
    $shop = Shop::factory()->create();
    $mine = rejectedStock(Warehouse::factory()->for($shop)->create(), 101, 5);
    $other = rejectedStock(Warehouse::factory()->create(), 102, 5);
    $mine->update(['failed_amount' => 5, 'failed_at' => now(), 'failed_code' => 'NotFound']);
    $other->update(['failed_amount' => 5, 'failed_at' => now(), 'failed_code' => 'NotFound']);

    $this->artisan('app:reset-stock-errors', ['shop' => $shop->id])
        ->expectsOutput('Cleared errors for 1 stock(s).')
        ->assertSuccessful();

    expect($mine->fresh()->failed_at)->toBeNull()
        ->and($other->fresh()->failed_at)->not->toBeNull();
});

test('reset command rejects an unknown shop', function () {
    $this->artisan('app:reset-stock-errors', ['shop' => 999999])
        ->expectsOutput('Shop not found.')
        ->assertFailed();
});

test('reset command also clears a pending miss counter', function () {
    $shop = Shop::factory()->create();
    $stock = rejectedStock(Warehouse::factory()->for($shop)->create(), 101, 5);
    $stock->update(['not_applied_count' => 1, 'not_applied_at' => now()]);

    $this->artisan('app:reset-stock-errors', ['shop' => $shop->id])
        ->expectsOutput('Cleared errors for 1 stock(s).')
        ->assertSuccessful();

    expect($stock->fresh()->not_applied_count)->toBe(0);
});
