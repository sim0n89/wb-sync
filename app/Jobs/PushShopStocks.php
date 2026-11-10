<?php

namespace App\Jobs;

use App\Enums\StocksErrorType;
use App\Enums\StockUploadStatus;
use App\Exceptions\Wildberries\RateLimitExceededException;
use App\Exceptions\Wildberries\StocksRejectedException;
use App\Jobs\Concerns\ShopJob;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\StockUpload;
use App\Models\Warehouse;
use App\Services\Wildberries\Data\StockItemDto;
use App\Services\Wildberries\WildberriesClient;
use App\Support\ValuesById;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PushShopStocks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use ShopJob;

    public const int MAX_BATCHES_PER_WAREHOUSE = 100;

    public const int MAX_SPLIT_REQUESTS = 32;

    private int $splitRequestsLeft = 0;

    /**
     * Create a new job instance.
     */
    public function __construct(public Shop $shop) {}

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public static function getStocksLockKey(Shop $shop): string
    {
        return 'stocks-'.$shop->id;
    }

    protected function getSharedOverlapKey(): ?string
    {
        return self::getStocksLockKey($this->shop);
    }

    protected function shouldWaitWhenBusy(): bool
    {
        return true;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->shop->is_active) {
            return;
        }

        $client = WildberriesClient::forShop($this->shop);
        $this->splitRequestsLeft = self::MAX_SPLIT_REQUESTS;

        $warehouses = $this->shop->warehouses()
            ->active()
            ->withMax('stocks', 'synced_at')
            ->orderBy('stocks_max_synced_at')
            ->orderBy('id')
            ->get();

        $this->runForEachGroup(
            $warehouses,
            fn (Warehouse $warehouse) => $this->pushWarehouse($client, $warehouse),
        );
    }

    private function pushWarehouse(WildberriesClient $client, Warehouse $warehouse): void
    {
        $lastId = 0;

        for ($i = 0; $i < self::MAX_BATCHES_PER_WAREHOUSE; $i++) {
            if ($this->isTimeBudgetExceeded()) {
                return;
            }

            $batch = $warehouse->stocks()
                ->needsSync()
                ->where('id', '>', $lastId)
                ->with('size')
                ->orderBy('id')
                ->limit(WildberriesClient::MAX_STOCKS_PER_REQUEST)
                ->get();

            if ($batch->isEmpty()) {
                return;
            }

            try {
                $this->pushBatch($client, $warehouse, $batch);
            } catch (StocksRejectedException $e) {
                if ($e->type === StocksErrorType::Temporary) {
                    return;
                }

                throw $e;
            }

            $lastId = $batch->last()->id;
        }
    }

    /**
     * @param  Collection<int, Stock>  $batch
     */
    private function pushBatch(WildberriesClient $client, Warehouse $warehouse, Collection $batch): void
    {
        $items = [];

        foreach ($batch as $stock) {
            $items[] = new StockItemDto(chrtId: $stock->size->chrt_id, amount: $stock->amount);
        }

        $upload = $this->createUpload($warehouse, $batch);

        try {
            $client->updateStocks($warehouse->wb_warehouse_id, $items);
        } catch (Throwable $e) {
            $this->markFailed($upload, $e);

            if ($e instanceof StocksRejectedException && $e->type === StocksErrorType::Item) {
                $this->handleRejectedItems($client, $warehouse, $batch, $e);

                return;
            }

            if (! $this->isDefinitelyRejected($e)) {
                Stock::whereKey($batch->modelKeys())->update(['synced_amount' => null, 'synced_at' => null]);
            }

            throw $e;
        }

        DB::transaction(function () use ($upload, $batch) {
            $upload->update([
                'status' => StockUploadStatus::Sent,
                'response_status' => 204,
            ]);

            $this->markSynced($batch);
        });
    }

    private function isDefinitelyRejected(Throwable $e): bool
    {
        if ($e instanceof RateLimitExceededException || $e instanceof StocksRejectedException) {
            return true;
        }

        return $e instanceof RequestException && $e->response->clientError();
    }

    /**
     * @param  Collection<int, Stock>  $batch
     */
    private function handleRejectedItems(WildberriesClient $client, Warehouse $warehouse, Collection $batch, StocksRejectedException $e): void
    {
        $rejectedByCode = [];
        $rest = new Collection;

        foreach ($batch as $stock) {
            $error = $e->itemErrors[$stock->size->chrt_id] ?? null;

            if ($error === null) {
                $rest->push($stock);

                continue;
            }

            $rejectedByCode[$error['code']]['message'] = $error['message'];
            $rejectedByCode[$error['code']]['stocks'][] = $stock;
        }

        if ($rejectedByCode !== []) {
            foreach ($rejectedByCode as $code => $rejected) {
                $this->markStocksFailed(new Collection($rejected['stocks']), $code, $rejected['message']);
            }

            $this->pushRemaining($client, $warehouse, $rest);

            return;
        }

        if ($batch->count() === 1) {
            $this->markStocksFailed($batch, $e->errorCode, $e->getMessage());

            return;
        }

        foreach ($batch->chunk((int) ceil($batch->count() / 2)) as $half) {
            $this->pushRemaining($client, $warehouse, $half);
        }
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     */
    private function pushRemaining(WildberriesClient $client, Warehouse $warehouse, Collection $stocks): void
    {
        if ($stocks->isEmpty() || $this->splitRequestsLeft <= 0 || $this->isTimeBudgetExceeded()) {
            return;
        }

        $this->splitRequestsLeft--;
        $this->pushBatch($client, $warehouse, $stocks);
    }

    /**
     * @param  Collection<int, Stock>  $batch
     */
    private function markSynced(Collection $batch): void
    {
        Stock::whereKey($batch->modelKeys())->update([
            'synced_amount' => ValuesById::toCase(Stock::class, $this->getAmountsById($batch)),
            'synced_at' => now(),
        ]);

        Stock::whereKey($batch->modelKeys())
            ->whereNotNull('failed_code')
            ->update(Stock::NO_FAILURE);
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     */
    private function markStocksFailed(Collection $stocks, string $code, string $message): void
    {
        Stock::whereKey($stocks->modelKeys())->update([
            'failed_amount' => ValuesById::toCase(Stock::class, $this->getAmountsById($stocks)),
            'failed_at' => now(),
            'failed_code' => $code,
            'failed_message' => Str::limit($message, 1000),
        ]);
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     * @return array<int, int>
     */
    private function getAmountsById(Collection $stocks): array
    {
        return $stocks->mapWithKeys(fn (Stock $stock) => [$stock->id => $stock->amount])->all();
    }

    private function markFailed(StockUpload $upload, Throwable $e): void
    {
        $responseStatus = null;
        $error = $e->getMessage();

        if ($e instanceof RateLimitExceededException) {
            $responseStatus = 429;
        }

        if ($e instanceof StocksRejectedException) {
            $responseStatus = $e->status;
            $error = "{$e->errorCode}: {$e->getMessage()}";
        }

        if ($e instanceof RequestException) {
            $responseStatus = $e->response->status();
            $error = $e->response->body();
        }

        $upload->update([
            'status' => StockUploadStatus::Failed,
            'response_status' => $responseStatus,
            'error' => Str::limit($error, 1000),
        ]);
    }

    /**
     * @param  Collection<int, Stock>  $batch
     */
    private function createUpload(Warehouse $warehouse, Collection $batch): StockUpload
    {
        return DB::transaction(function () use ($warehouse, $batch) {
            $upload = StockUpload::create([
                'shop_id' => $this->shop->id,
                'warehouse_id' => $warehouse->id,
                'status' => StockUploadStatus::InProgress,
                'items_count' => $batch->count(),
            ]);

            $now = now();
            $rows = [];

            foreach ($batch as $stock) {
                $rows[] = [
                    'stock_upload_id' => $upload->id,
                    'stock_id' => $stock->id,
                    'chrt_id' => $stock->size->chrt_id,
                    'amount' => $stock->amount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $upload->items()->insert($rows);

            return $upload;
        });
    }
}
