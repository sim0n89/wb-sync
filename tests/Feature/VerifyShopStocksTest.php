<?php

use App\Enums\StockUploadStatus;
use App\Jobs\PushShopStocks;
use App\Jobs\VerifyShopStocks;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\StockUpload;
use App\Models\StockUploadItem;
use App\Models\Warehouse;
use App\Services\FakeWildberries\FakeStockStore;
use App\Services\Wildberries\WildberriesClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();
    config(['services.wildberries.marketplace_url' => 'https://wb.test']);
});

function sentUpload(Warehouse $warehouse, array $amounts, int $minutesAgo = 5): StockUpload
{
    $upload = StockUpload::factory()->for($warehouse)->create([
        'shop_id' => $warehouse->shop_id,
        'status' => StockUploadStatus::Sent,
        'response_status' => 204,
        'items_count' => count($amounts),
    ]);

    foreach ($amounts as $chrtId => $amount) {
        $size = ProductSize::factory()->for(Product::factory()->for($warehouse->shop))->create(['chrt_id' => $chrtId]);
        $stock = Stock::factory()->for($warehouse)->for($size, 'size')->create([
            'amount' => $amount,
            'synced_amount' => $amount,
            'synced_at' => now(),
        ]);
        $upload->items()->create(['stock_id' => $stock->id, 'chrt_id' => $chrtId, 'amount' => $amount]);
    }

    StockUpload::whereKey($upload->id)->update([
        'created_at' => now()->subMinutes($minutesAgo),
        'updated_at' => now()->subMinutes($minutesAgo),
    ]);

    return $upload->fresh();
}

test('client reads stocks with post and chrt ids', function () {
    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 1, 'amount' => 11]]])]);

    $amounts = (new WildberriesClient('token', 'https://wb.test', 'https://content.test'))->getStocks(507, [1, 2]);

    expect($amounts)->toBe([1 => 11]);
    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://wb.test/api/v3/stocks/507'
            && $request->data() === ['chrtIds' => [1, 2]];
    });
});

test('upload is verified when wildberries holds what was sent', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 507]), [1 => 5, 2 => 0]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 1, 'amount' => 5], ['chrtId' => 2, 'amount' => 0]]])]);

    VerifyShopStocks::dispatchSync($shop);

    $upload->refresh();

    expect($upload->status)->toBe(StockUploadStatus::Verified)
        ->and($upload->verified_at)->not->toBeNull()
        ->and($upload->items()->orderBy('chrt_id')->pluck('wb_amount')->all())->toBe([5, 0])
        ->and(Stock::needsSync()->count())->toBe(0);
});

test('wildberries holding more than was sent is sent again right away', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 507]), [1 => 5, 2 => 7]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 1, 'amount' => 5], ['chrtId' => 2, 'amount' => 9]]])]);

    VerifyShopStocks::dispatchSync($shop);

    $upload->refresh();
    $stale = Stock::whereRelation('size', 'chrt_id', 2)->sole();

    expect($upload->status)->toBe(StockUploadStatus::Mismatch)
        ->and($upload->error)->toContain('chrtId 2: отправлено 7, в WB 9')
        ->and($upload->items()->where('chrt_id', 2)->value('wb_amount'))->toBe(9)
        ->and($stale->synced_amount)->toBeNull()
        ->and($stale->not_applied_count)->toBe(1)
        ->and($stale->failed_code)->toBeNull()
        ->and(Stock::needsSync()->pluck('id')->all())->toBe([$stale->id]);
});

test('wildberries holding less than was sent is reported but never sent again so stock is not inflated', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(), [2 => 7]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 3]]])]);

    VerifyShopStocks::dispatchSync($shop);

    $stock = Stock::sole();

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Mismatch)
        ->and($stock->not_applied_count)->toBe(1)
        ->and($stock->synced_amount)->toBe(7)
        ->and(Stock::needsSync()->count())->toBe(0);
});

test('later failed upload of the same stock makes the earlier one outdated', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    $upload = sentUpload($warehouse, [2 => 5]);
    $stock = Stock::sole();
    $failed = StockUpload::factory()->for($warehouse)->create(['shop_id' => $shop->id, 'status' => StockUploadStatus::Failed]);
    $failed->items()->create(['stock_id' => $stock->id, 'chrt_id' => 2, 'amount' => 3]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 3]]])]);

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Unverified)
        ->and($stock->fresh()->not_applied_count)->toBe(0);
});

test('repeated miss is counted but never blocks the stock', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    sentUpload(Warehouse::factory()->for($shop)->create(), [2 => 7]);
    $stale = Stock::sole();
    $stale->update(['not_applied_count' => 3, 'not_applied_at' => now()->subHour()]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 9]]])]);

    VerifyShopStocks::dispatchSync($shop);

    $stale->refresh();

    expect($stale->not_applied_count)->toBe(4)
        ->and($stale->not_applied_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($stale->failed_code)->toBeNull()
        ->and(Stock::needsSync()->pluck('id')->all())->toBe([$stale->id]);
});

test('match clears the miss counter', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    sentUpload(Warehouse::factory()->for($shop)->create(), [2 => 7]);
    Stock::query()->update(['not_applied_count' => 1, 'not_applied_at' => now()->subHour()]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 7]]])]);

    VerifyShopStocks::dispatchSync($shop);

    $stock = Stock::sole();

    expect($stock->not_applied_count)->toBe(0)
        ->and($stock->not_applied_at)->toBeNull();
});

test('size with an order on the same warehouse after the upload is not judged', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 507]), [2 => 7]);
    Order::factory()->for($shop)->create(['chrt_id' => 2, 'warehouse_id' => 507, 'wb_created_at' => now()->subMinutes(3)]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 6]]])]);

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Unverified)
        ->and($upload->items()->value('wb_amount'))->toBeNull()
        ->and(Stock::sole()->synced_amount)->toBe(7)
        ->and(Stock::sole()->not_applied_count)->toBe(0);
});

test('order on another warehouse does not hide a miss', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 507]), [2 => 7]);
    Order::factory()->for($shop)->create(['chrt_id' => 2, 'warehouse_id' => 508, 'wb_created_at' => now()->subMinutes(3)]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 6]]])]);

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Mismatch);
});

test('upload made after the last orders sync waits for the next one', function () {
    $shop = Shop::factory()->create(['orders_synced_at' => now()->subMinutes(10)]);
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(), [2 => 7]);

    Http::fake();

    VerifyShopStocks::dispatchSync($shop);

    Http::assertNothingSent();
    expect($upload->fresh()->status)->toBe(StockUploadStatus::Sent);
});

test('miss counter does not grow past the limit', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    sentUpload(Warehouse::factory()->for($shop)->create(), [2 => 7]);
    Stock::query()->update(['not_applied_count' => VerifyShopStocks::MAX_NOT_APPLIED_COUNT, 'not_applied_at' => now()->subHour()]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 3]]])]);

    VerifyShopStocks::dispatchSync($shop);

    expect(Stock::sole()->not_applied_count)->toBe(VerifyShopStocks::MAX_NOT_APPLIED_COUNT);
});

test('later upload rejected by wildberries does not make the earlier one outdated', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    $upload = sentUpload($warehouse, [2 => 5]);
    $rejected = StockUpload::factory()->for($warehouse)->create([
        'shop_id' => $shop->id,
        'status' => StockUploadStatus::Failed,
        'response_status' => 409,
    ]);
    $rejected->items()->create(['stock_id' => Stock::sole()->id, 'chrt_id' => 2, 'amount' => 3]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 9]]])]);

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Mismatch)
        ->and(Stock::sole()->synced_amount)->toBeNull();
});

test('stocks of an interrupted upload are sent again', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    sentUpload($warehouse, [2 => 5]);
    $stock = Stock::sole();
    $upload = StockUpload::factory()->for($warehouse)->create(['shop_id' => $shop->id]);
    $upload->items()->create(['stock_id' => $stock->id, 'chrt_id' => 2, 'amount' => 5]);
    StockUpload::whereKey($upload->id)->update(['updated_at' => now()->subMinutes(VerifyShopStocks::WINDOW_MINUTES + 1)]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 5]]])]);

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Interrupted)
        ->and($stock->fresh()->synced_amount)->toBeNull()
        ->and(Stock::needsSync()->pluck('id')->all())->toBe([$stock->id]);
});

test('upload left in progress by a killed worker is marked interrupted', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = StockUpload::factory()->for(Warehouse::factory()->for($shop)->create())->create(['shop_id' => $shop->id]);
    StockUpload::whereKey($upload->id)->update(['updated_at' => now()->subMinutes(VerifyShopStocks::WINDOW_MINUTES + 1)]);

    Http::fake();

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Interrupted);
});

test('item rejection mark is not overwritten by a mismatch', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    sentUpload(Warehouse::factory()->for($shop)->create(), [2 => 5]);
    Stock::query()->update([
        'amount' => 3,
        'failed_amount' => 3,
        'failed_at' => now(),
        'failed_code' => 'TnvedRequired',
    ]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 1]]])]);

    VerifyShopStocks::dispatchSync($shop);

    $stock = Stock::sole();

    expect($stock->failed_code)->toBe('TnvedRequired')
        ->and($stock->failed_amount)->toBe(3)
        ->and($stock->synced_amount)->toBe(5);
});

test('sent zero is applied when wildberries leaves the size out of the answer', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(), [1 => 0]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => []])]);

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Verified)
        ->and($upload->items()->value('wb_amount'))->toBe(0);
});

test('read rejected by wildberries marks those uploads unverified and checks the others', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $broken = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]), [1 => 5]);
    $healthy = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]), [2 => 5]);

    Http::fake([
        'wb.test/api/v3/stocks/1' => Http::response(['code' => 'NotFound', 'message' => 'Not found'], 404),
        'wb.test/api/v3/stocks/2' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 5]]]),
    ]);

    VerifyShopStocks::dispatchSync($shop);

    expect($healthy->fresh()->status)->toBe(StockUploadStatus::Verified)
        ->and($broken->fresh()->status)->toBe(StockUploadStatus::Unverified)
        ->and($broken->fresh()->error)->toContain('404');
});

test('server error while reading does not stop checking the others', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $broken = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]), [1 => 5]);
    $healthy = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]), [2 => 5]);

    Http::fake([
        'wb.test/api/v3/stocks/1' => Http::response('oops', 500),
        'wb.test/api/v3/stocks/2' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 5]]]),
    ]);

    expect(function () use ($shop) {
        VerifyShopStocks::dispatchSync($shop);
    })->toThrow(RequestException::class);

    expect($healthy->fresh()->status)->toBe(StockUploadStatus::Verified)
        ->and($broken->fresh()->status)->toBe(StockUploadStatus::Sent);
});

test('stocks push and verification of one shop never run at the same time', function (string $jobClass) {
    Http::fake();

    $shop = Shop::factory()->ordersSynced()->create();
    sentUpload(Warehouse::factory()->for($shop)->create(), [1 => 5]);
    Stock::query()->update(['amount' => 9]);

    $job = new $jobClass($shop);
    $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 60);
    $lock->get();

    $otherJob = $jobClass === PushShopStocks::class ? VerifyShopStocks::class : PushShopStocks::class;
    $otherJob::dispatchSync($shop);

    Http::assertNothingSent();
    $lock->release();
})->with([PushShopStocks::class, VerifyShopStocks::class]);

test('size missing in wildberries counts as mismatch', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(), [1 => 5]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => []])]);

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Mismatch)
        ->and($upload->fresh()->error)->toContain('в WB нет');
});

test('item already sent again with another amount is not judged', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(), [1 => 5]);
    Stock::query()->update(['amount' => 9, 'synced_amount' => 9]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 1, 'amount' => 9]]])]);

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Unverified)
        ->and(Stock::sole()->synced_amount)->toBe(9);
});

test('too fresh uploads wait and too old ones are marked unverified', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $warehouse = Warehouse::factory()->for($shop)->create();
    $fresh = sentUpload($warehouse, [1 => 5], minutesAgo: 0);
    $old = sentUpload($warehouse, [2 => 5], minutesAgo: VerifyShopStocks::WINDOW_MINUTES + 5);

    Http::fake();

    VerifyShopStocks::dispatchSync($shop);

    Http::assertNothingSent();
    expect($fresh->fresh()->status)->toBe(StockUploadStatus::Sent)
        ->and($old->fresh()->status)->toBe(StockUploadStatus::Unverified);
});

test('rate limit releases the verification job', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(), [1 => 5]);

    Http::fake(['wb.test/*' => Http::response([], 429, ['X-Ratelimit-Retry' => '4'])]);

    $job = (new VerifyShopStocks($shop))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(delay: 4);
    expect($upload->fresh()->status)->toBe(StockUploadStatus::Sent);
});

test('verify command queues a job per active shop and runs three minutes after the stocks push', function () {
    Queue::fake();
    Shop::factory()->count(2)->create();
    Shop::factory()->create(['is_active' => false]);

    $this->artisan('app:verify-stocks')
        ->expectsOutput('Queued stocks check for 2 shop(s).')
        ->assertSuccessful();

    Queue::assertPushed(VerifyShopStocks::class, 2);

    $found = false;
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains($event->command, 'app:verify-stocks')) {
            $found = $event->expression === '3-59/10 * * * *' && $event->withoutOverlapping && $event->onOneServer;
        }
    }

    expect($found)->toBeTrue();
});

test('silently dropped lower amount is caught end to end and sent again', function () {
    config(['services.wildberries.marketplace_url' => 'http://fake.test/fake-wb']);

    Http::fake(function (Request $request) {
        $response = $this->call(
            $request->method(),
            parse_url($request->url(), PHP_URL_PATH),
            server: ['HTTP_AUTHORIZATION' => $request->header('Authorization')[0], 'CONTENT_TYPE' => 'application/json'],
            content: $request->body(),
        );

        return Http::response($response->getContent(), $response->getStatusCode());
    });

    $shop = Shop::factory()->ordersSynced()->create(['wb_token' => 'demo-token']);
    $this->artisan('app:demo-catalog', ['shop' => $shop->id, '--products' => 1])->assertSuccessful();
    Stock::query()->update(['amount' => 10]);

    PushShopStocks::dispatchSync($shop);

    $dropped = Stock::with('size', 'warehouse')->orderBy('id')->first();
    app(FakeStockStore::class)->setChrtDropped('demo-token', $dropped->size->chrt_id, true);
    $dropped->update(['amount' => 4]);

    $this->travel(1)->minutes();
    PushShopStocks::dispatchSync($shop);

    $this->travel(3)->minutes();
    $shop->update(['orders_synced_at' => now()]);
    VerifyShopStocks::dispatchSync($shop);

    $dropped->refresh();
    $mismatch = StockUpload::where('status', StockUploadStatus::Mismatch)->sole();

    expect($mismatch->error)->toContain('отправлено 4, в WB 10')
        ->and($dropped->not_applied_count)->toBe(1)
        ->and($dropped->synced_amount)->toBeNull()
        ->and(Stock::needsSync()->pluck('id')->all())->toBe([$dropped->id])
        ->and(StockUpload::where('status', StockUploadStatus::Verified)->count())->toBeGreaterThan(0);
});

test('size missing in wildberries is not sent again so an unseen sell out is not undone', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    sentUpload(Warehouse::factory()->for($shop)->create(), [2 => 5]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => []])]);

    VerifyShopStocks::dispatchSync($shop);

    expect(Stock::sole()->synced_amount)->toBe(5)
        ->and(Stock::needsSync()->count())->toBe(0);
});

test('upload where nothing could be judged is not reported as verified', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $upload = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 507]), [2 => 7]);
    Order::factory()->for($shop)->create(['chrt_id' => 2, 'warehouse_id' => 507, 'wb_created_at' => now()->subMinutes(3)]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [['chrtId' => 2, 'amount' => 6]]])]);

    VerifyShopStocks::dispatchSync($shop);

    expect($upload->fresh()->status)->toBe(StockUploadStatus::Unverified)
        ->and($upload->fresh()->verified_at)->toBeNull();
});

test('error on one warehouse skips its remaining uploads but not other warehouses', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $broken = Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]);
    $healthy = Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]);

    $brokenUploads = collect(range(1, VerifyShopStocks::UPLOADS_PER_CHECK + 1))
        ->map(fn (int $chrtId) => sentUpload($broken, [$chrtId => 5]));
    $healthyUpload = sentUpload($healthy, [500 => 7]);

    Http::fake([
        'wb.test/api/v3/stocks/1' => Http::response(null, 500),
        'wb.test/api/v3/stocks/2' => Http::response(['stocks' => [['chrtId' => 500, 'amount' => 7]]]),
    ]);

    expect(function () use ($shop) {
        VerifyShopStocks::dispatchSync($shop);
    })->toThrow(RequestException::class);

    Http::assertSentCount(2);
    expect($healthyUpload->fresh()->status)->toBe(StockUploadStatus::Verified)
        ->and(StockUpload::whereKey($brokenUploads->pluck('id'))->pluck('status')->unique()->all())
        ->toBe([StockUploadStatus::Sent]);
});

test('verification stops when the time budget is spent and the rest is checked next time', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    $first = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 1]), [1 => 5]);
    $later = sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 2]), [2 => 7]);

    Http::fake(function (Request $request) {
        $this->travel(250)->seconds();

        return Http::response(['stocks' => [['chrtId' => $request->data()['chrtIds'][0], 'amount' => $request->data()['chrtIds'][0] === 1 ? 5 : 7]]]);
    });

    VerifyShopStocks::dispatchSync($shop);

    Http::assertSentCount(1);
    expect($first->fresh()->status)->toBe(StockUploadStatus::Verified)
        ->and($later->fresh()->status)->toBe(StockUploadStatus::Sent);
});

test('each missed stock is counted and sent again by its own amount when several are written at once', function () {
    $shop = Shop::factory()->ordersSynced()->create();
    sentUpload(Warehouse::factory()->for($shop)->create(['wb_warehouse_id' => 507]), [1 => 5, 2 => 7, 3 => 2]);

    Http::fake(['wb.test/*' => Http::response(['stocks' => [
        ['chrtId' => 1, 'amount' => 8],
        ['chrtId' => 2, 'amount' => 9],
        ['chrtId' => 3, 'amount' => 2],
    ]])]);

    VerifyShopStocks::dispatchSync($shop);

    $stocks = Stock::with('size')->get()->keyBy('size.chrt_id');

    expect($stocks->map(fn (Stock $stock) => [$stock->not_applied_count, $stock->synced_amount])->sortKeys()->all())
        ->toBe([1 => [1, null], 2 => [1, null], 3 => [0, 2]])
        ->and(StockUploadItem::orderBy('chrt_id')->pluck('wb_amount')->all())->toBe([8, 9, 2]);
});
