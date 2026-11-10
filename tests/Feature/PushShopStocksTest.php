<?php

use App\Enums\StockUploadStatus;
use App\Exceptions\Wildberries\StocksRejectedException;
use App\Jobs\PushShopStocks;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\StockUpload;
use App\Models\Warehouse;
use App\Services\Wildberries\WildberriesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);
});

function stockFor(Warehouse $warehouse, int $chrtId, int $amount, ?int $syncedAmount = null): Stock
{
    $product = Product::factory()->for($warehouse->shop)->create();
    $size = ProductSize::factory()->for($product)->create(['chrt_id' => $chrtId]);

    return Stock::factory()->for($warehouse)->for($size, 'size')->create([
        'amount' => $amount,
        'synced_amount' => $syncedAmount,
        'synced_at' => $syncedAmount === null ? null : now(),
    ]);
}

test('changed stocks are pushed per warehouse and marked as synced', function () {
    Http::fake(['wb.test/*' => Http::response(null, 204)]);

    $shop = Shop::factory()->create();
    $warehouse = Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 658434]);
    $changed = stockFor($warehouse, 101, 11, 3);
    $new = stockFor($warehouse, 102, 0);
    $actual = stockFor($warehouse, 103, 5, 5);

    PushShopStocks::dispatchSync($shop);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://wb.test/api/v3/stocks/658434'
            && $request->data() === ['stocks' => [
                ['chrtId' => 101, 'amount' => 11],
                ['chrtId' => 102, 'amount' => 0],
            ]];
    });

    expect($changed->fresh()->synced_amount)->toBe(11)
        ->and($new->fresh()->synced_amount)->toBe(0)
        ->and($actual->fresh()->synced_at->eq($actual->synced_at))->toBeTrue()
        ->and(Stock::needsSync()->count())->toBe(0);

    $upload = StockUpload::sole();

    expect($upload->status)->toBe(StockUploadStatus::Sent)
        ->and($upload->response_status)->toBe(204)
        ->and($upload->items_count)->toBe(2)
        ->and($upload->items()->orderBy('chrt_id')->pluck('amount', 'chrt_id')->all())->toBe([101 => 11, 102 => 0]);
});

test('nothing is sent when all stocks are actual', function () {
    Http::fake();

    $shop = Shop::factory()->create();
    stockFor(Warehouse::factory()->for($shop)->create(), 101, 5, 5);

    PushShopStocks::dispatchSync($shop);

    Http::assertNothingSent();
    expect(StockUpload::count())->toBe(0);
});

test('stocks are split into batches of 1000', function () {
    Http::fake(['wb.test/*' => Http::response(null, 204)]);

    $shop = Shop::factory()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    $product = Product::factory()->for($shop)->create();
    $sizes = ProductSize::factory()->for($product)->count(WildberriesClient::MAX_STOCKS_PER_REQUEST + 1)->create();

    $rows = [];
    foreach ($sizes as $size) {
        $rows[] = ['warehouse_id' => $warehouse->id, 'product_size_id' => $size->id, 'amount' => 1];
    }
    Stock::insert($rows);

    PushShopStocks::dispatchSync($shop);

    $sizes = [];
    Http::assertSent(function (Request $request) use (&$sizes) {
        $sizes[] = count($request->data()['stocks']);

        return true;
    });

    expect($sizes)->toBe([1000, 1])
        ->and(StockUpload::where('status', StockUploadStatus::Sent)->count())->toBe(2)
        ->and(Stock::needsSync()->count())->toBe(0);
});

test('each warehouse gets its own request and inactive warehouse is skipped', function () {
    Http::fake(['wb.test/*' => Http::response(null, 204)]);

    $shop = Shop::factory()->create();
    stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]), 101, 1);
    stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]), 102, 2);
    stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 3, 'is_active' => false]), 103, 3);

    PushShopStocks::dispatchSync($shop);

    $urls = [];
    Http::assertSent(function (Request $request) use (&$urls) {
        $urls[] = $request->url();

        return true;
    });

    sort($urls);
    expect($urls)->toBe(['https://wb.test/api/v3/stocks/1', 'https://wb.test/api/v3/stocks/2']);
});

test('stock changed during upload remembers what wildberries got and goes in the next run', function () {
    $shop = Shop::factory()->create();
    $stock = stockFor(Warehouse::factory()->for($shop)->create(), 101, 10);

    $sentAmounts = [];

    Http::fake(function (Request $request) use ($stock, &$sentAmounts) {
        $sentAmounts[] = $request->data()['stocks'][0]['amount'];

        if (count($sentAmounts) === 1) {
            Stock::whereKey($stock->id)->update(['amount' => 4]);
        }

        return Http::response(null, 204);
    });

    PushShopStocks::dispatchSync($shop);

    expect($sentAmounts)->toBe([10])
        ->and($stock->fresh()->synced_amount)->toBe(10)
        ->and(Stock::needsSync()->pluck('id')->all())->toBe([$stock->id]);

    PushShopStocks::dispatchSync($shop);

    expect($sentAmounts)->toBe([10, 4])
        ->and($stock->fresh()->synced_amount)->toBe(4);
});

test('amount that goes away and comes back during upload is sent again', function () {
    $shop = Shop::factory()->create();
    $stock = stockFor(Warehouse::factory()->for($shop)->create(), 101, 5, 5);
    $stock->update(['amount' => 7]);

    $sentAmounts = [];

    Http::fake(function (Request $request) use ($stock, &$sentAmounts) {
        $sentAmounts[] = $request->data()['stocks'][0]['amount'];

        if (count($sentAmounts) === 1) {
            Stock::whereKey($stock->id)->update(['amount' => 5]);
        }

        return Http::response(null, 204);
    });

    PushShopStocks::dispatchSync($shop);
    PushShopStocks::dispatchSync($shop);

    expect($sentAmounts)->toBe([7, 5])
        ->and($stock->fresh()->synced_amount)->toBe(5);
});

test('run stops when the time budget is spent and the rest goes next time', function () {
    $this->freezeTime();

    $shop = Shop::factory()->create();
    stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]), 101, 1);
    $later = stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]), 102, 2);

    Http::fake(function () {
        $this->travel(250)->seconds();

        return Http::response(null, 204);
    });

    PushShopStocks::dispatchSync($shop);

    Http::assertSentCount(1);
    expect($later->fresh()->synced_amount)->toBeNull();

    PushShopStocks::dispatchSync($shop);

    expect($later->fresh()->synced_amount)->toBe(2);
});

test('warehouse pushed longest ago goes first so a busy one cannot starve the rest', function () {
    $this->freezeTime();

    $shop = Shop::factory()->create();
    $busy = Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]);
    stockFor($busy, 101, 1, 1);
    stockFor($busy, 102, 5);
    $quiet = stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]), 103, 2);

    Http::fake(function () {
        $this->travel(250)->seconds();

        return Http::response(null, 204);
    });

    PushShopStocks::dispatchSync($shop);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://wb.test/api/v3/stocks/2';
    });
    expect($quiet->fresh()->synced_amount)->toBe(2);
});

test('rate limit after a real error does not hide the error', function () {
    Http::fake([
        'wb.test/api/v3/stocks/1' => Http::response(['code' => 'IncorrectParameter', 'message' => 'Incorrect parameter'], 400),
        'wb.test/api/v3/stocks/2' => Http::response([], 429, ['X-Ratelimit-Retry' => '5']),
    ]);

    $shop = Shop::factory()->create();
    stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]), 101, 1);
    stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]), 102, 2);

    $job = (new PushShopStocks($shop))->withFakeQueueInteractions();

    expect(function () use ($job) {
        $job->handle();
    })->toThrow(StocksRejectedException::class);

    $job->assertNotReleased();
});

test('rate limit marks upload as failed and releases the job', function () {
    Http::fake(['wb.test/*' => Http::response([], 429, ['X-Ratelimit-Retry' => '5'])]);

    $shop = Shop::factory()->create();
    $stock = stockFor(Warehouse::factory()->for($shop)->create(), 101, 10);

    $job = (new PushShopStocks($shop))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(delay: 5);

    $upload = StockUpload::sole();

    expect($upload->status)->toBe(StockUploadStatus::Failed)
        ->and($upload->response_status)->toBe(429)
        ->and($stock->fresh()->synced_amount)->toBeNull();
});

test('wildberries error is saved in upload history and rethrown', function () {
    Http::fake(['wb.test/*' => Http::response(['code' => 'IncorrectRequestBody', 'message' => 'Incorrect required body'], 400)]);

    $shop = Shop::factory()->create();
    $stock = stockFor(Warehouse::factory()->for($shop)->create(), 101, 10);

    expect(function () use ($shop) {
        PushShopStocks::dispatchSync($shop);
    })->toThrow(StocksRejectedException::class);

    $upload = StockUpload::sole();

    expect($upload->status)->toBe(StockUploadStatus::Failed)
        ->and($upload->response_status)->toBe(400)
        ->and($upload->error)->toContain('IncorrectRequestBody')
        ->and($upload->items()->count())->toBe(1)
        ->and($stock->fresh()->synced_amount)->toBeNull();
});

test('error on one warehouse does not stop other warehouses', function () {
    Http::fake([
        'wb.test/api/v3/stocks/1' => Http::response(['code' => 'NotFound', 'message' => 'Not found'], 404),
        'wb.test/api/v3/stocks/2' => Http::response(null, 204),
    ]);

    $shop = Shop::factory()->create();
    $broken = stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]), 101, 1);
    $healthy = stockFor(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]), 102, 2);

    expect(function () use ($shop) {
        PushShopStocks::dispatchSync($shop);
    })->toThrow(StocksRejectedException::class);

    expect($healthy->fresh()->synced_amount)->toBe(2)
        ->and($broken->fresh()->synced_amount)->toBeNull()
        ->and(StockUpload::where('status', StockUploadStatus::Failed)->value('response_status'))->toBe(404);
});

test('connection error marks upload as failed instead of leaving it in progress', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $shop = Shop::factory()->create();
    stockFor(Warehouse::factory()->for($shop)->create(), 101, 1);

    expect(function () use ($shop) {
        PushShopStocks::dispatchSync($shop);
    })->toThrow(ConnectionException::class);

    $upload = StockUpload::sole();

    expect($upload->status)->toBe(StockUploadStatus::Failed)
        ->and($upload->response_status)->toBeNull()
        ->and($upload->error)->toContain('timed out');
});

test('push with unknown outcome is sent again even if the amount returns to the old value', function () {
    $shop = Shop::factory()->create();
    $stock = stockFor(Warehouse::factory()->for($shop)->create(), 101, 5, 5);
    $stock->update(['amount' => 8]);

    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    expect(function () use ($shop) {
        PushShopStocks::dispatchSync($shop);
    })->toThrow(ConnectionException::class);

    $stock->refresh();
    $stock->update(['amount' => 5]);

    expect($stock->synced_amount)->toBeNull()
        ->and(Stock::needsSync()->pluck('id')->all())->toBe([$stock->id]);
});

test('push rejected by wildberries keeps what was synced before', function () {
    $shop = Shop::factory()->create();
    $stock = stockFor(Warehouse::factory()->for($shop)->create(), 101, 5, 5);
    $stock->update(['amount' => 8]);

    Http::fake(['wb.test/*' => Http::response(['code' => 'IncorrectRequestBody', 'message' => 'Incorrect required body'], 400)]);

    expect(function () use ($shop) {
        PushShopStocks::dispatchSync($shop);
    })->toThrow(StocksRejectedException::class);

    expect($stock->fresh()->synced_amount)->toBe(5);
});

test('synced stocks with different amounts are marked with one query plus one to clear old errors', function () {
    Http::fake(['wb.test/*' => Http::response(null, 204)]);

    $shop = Shop::factory()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    $stocks = collect([5, 5, 5, 0, 0, 7])->map(fn (int $amount, int $i) => stockFor($warehouse, 200 + $i, $amount));

    $updates = 0;
    DB::listen(function ($query) use (&$updates) {
        if (str_starts_with($query->sql, 'update `stocks`')) {
            $updates++;
        }
    });

    PushShopStocks::dispatchSync($shop);

    expect($updates)->toBe(2)
        ->and($stocks->map(fn (Stock $stock) => $stock->fresh()->synced_amount)->all())->toBe([5, 5, 5, 0, 0, 7]);
});

test('upload is recorded as in progress before the request is sent', function () {
    $shop = Shop::factory()->create();
    stockFor(Warehouse::factory()->for($shop)->create(), 101, 10);

    $statusDuringRequest = null;

    Http::fake(function () use (&$statusDuringRequest) {
        $statusDuringRequest = StockUpload::sole()->status;

        return Http::response(null, 204);
    });

    PushShopStocks::dispatchSync($shop);

    expect($statusDuringRequest)->toBe(StockUploadStatus::InProgress)
        ->and(StockUpload::sole()->status)->toBe(StockUploadStatus::Sent);
});

test('second push of the same shop is not queued', function () {
    Queue::fake();

    $shop = Shop::factory()->create();

    PushShopStocks::dispatch($shop);
    PushShopStocks::dispatch($shop);

    Queue::assertPushed(PushShopStocks::class, 1);
});
