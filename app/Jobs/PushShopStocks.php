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

    /**
     * Create a new job instance.
     */
    public function __construct(public Shop $shop) {}

    public function getShop(): Shop
    {
        return $this->shop;
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
        $firstError = null;

        foreach ($this->shop->warehouses()->active()->get() as $warehouse) {
            if ($this->isTimeBudgetExceeded()) {
                break;
            }

            try {
                $this->pushWarehouse($client, $warehouse);
            } catch (RateLimitExceededException $e) {
                if ($firstError !== null) {
                    throw $firstError;
                }

                $this->release($e->retryAfter);

                return;
            } catch (Throwable $e) {
                $firstError ??= $e;
            }
        }

        if ($firstError !== null) {
            throw $firstError;
        }
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

    /**
     * @param  Collection<int, Stock>  $batch
     */
    private function handleRejectedItems(WildberriesClient $client, Warehouse $warehouse, Collection $batch, StocksRejectedException $e): void
    {
        $rejected = new Collection;
        $rest = new Collection;

        foreach ($batch as $stock) {
            if (in_array($stock->size->chrt_id, $e->chrtIds, true)) {
                $rejected->push($stock);
            } else {
                $rest->push($stock);
            }
        }

        if ($rejected->isNotEmpty()) {
            $this->markStocksFailed($rejected, $e);

            if ($rest->isNotEmpty()) {
                $this->pushBatch($client, $warehouse, $rest);
            }

            return;
        }

        if ($batch->count() === 1) {
            $this->markStocksFailed($batch, $e);

            return;
        }

        foreach ($batch->chunk((int) ceil($batch->count() / 2)) as $half) {
            $this->pushBatch($client, $warehouse, new Collection($half->values()->all()));
        }
    }

    /**
     * @param  Collection<int, Stock>  $batch
     */
    private function markSynced(Collection $batch): void
    {
        $now = now();

        foreach ($this->getIdsByAmount($batch) as $amount => $ids) {
            Stock::whereKey($ids)
                ->where('amount', $amount)
                ->update([
                    'synced_amount' => $amount,
                    'synced_at' => $now,
                    'failed_amount' => null,
                    'failed_at' => null,
                    'failed_code' => null,
                    'failed_message' => null,
                ]);
        }
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     */
    private function markStocksFailed(Collection $stocks, StocksRejectedException $e): void
    {
        $now = now();

        foreach ($this->getIdsByAmount($stocks) as $amount => $ids) {
            Stock::whereKey($ids)
                ->where('amount', $amount)
                ->update([
                    'failed_amount' => $amount,
                    'failed_at' => $now,
                    'failed_code' => $e->errorCode,
                    'failed_message' => Str::limit($e->getMessage(), 1000),
                ]);
        }
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     * @return array<int, array<int, int>>
     */
    private function getIdsByAmount(Collection $stocks): array
    {
        $idsByAmount = [];

        foreach ($stocks as $stock) {
            $idsByAmount[$stock->amount][] = $stock->id;
        }

        return $idsByAmount;
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
