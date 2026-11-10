<?php

namespace App\Jobs;

use App\Enums\StockUploadStatus;
use App\Jobs\Concerns\ShopJob;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\StockUpload;
use App\Models\StockUploadItem;
use App\Services\Wildberries\WildberriesClient;
use App\Support\ValuesById;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VerifyShopStocks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use ShopJob;

    public const int DELAY_SECONDS = 120;

    public const int WINDOW_MINUTES = 60;

    public const int UPLOADS_PER_CHECK = 20;

    public const int MAX_NOT_APPLIED_COUNT = 255;

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

        $this->closeStaleUploads();

        if ($this->shop->orders_synced_at === null) {
            return;
        }

        $client = WildberriesClient::forShop($this->shop);

        $uploads = StockUpload::query()
            ->where('shop_id', $this->shop->id)
            ->where('status', StockUploadStatus::Sent)
            ->whereBetween('updated_at', [
                now()->subMinutes(self::WINDOW_MINUTES),
                now()->subSeconds(self::DELAY_SECONDS),
            ])
            ->where('created_at', '<=', $this->shop->orders_synced_at)
            ->with('warehouse')
            ->orderBy('id')
            ->get();

        $this->runForEachGroup(
            $uploads->groupBy('warehouse_id'),
            function (Collection $warehouseUploads) use ($client): void {
                foreach ($warehouseUploads->chunk(self::UPLOADS_PER_CHECK) as $chunk) {
                    if ($this->isTimeBudgetExceeded()) {
                        return;
                    }

                    $this->verifyUploads($client, $chunk);
                }
            },
        );
    }

    protected function getSharedOverlapKey(): ?string
    {
        return PushShopStocks::getStocksLockKey($this->shop);
    }

    private function closeStaleUploads(): void
    {
        $staleBefore = now()->subMinutes(self::WINDOW_MINUTES);

        StockUpload::query()
            ->where('shop_id', $this->shop->id)
            ->where('status', StockUploadStatus::Sent)
            ->where('updated_at', '<', $staleBefore)
            ->update(['status' => StockUploadStatus::Unverified]);

        $interruptedIds = StockUpload::query()
            ->where('shop_id', $this->shop->id)
            ->where('status', StockUploadStatus::InProgress)
            ->where('updated_at', '<', $staleBefore)
            ->pluck('id');

        if ($interruptedIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($interruptedIds) {
            StockUpload::whereKey($interruptedIds)->update(['status' => StockUploadStatus::Interrupted]);

            Stock::whereIn('id', StockUploadItem::whereIn('stock_upload_id', $interruptedIds)->select('stock_id'))
                ->update(['synced_amount' => null, 'synced_at' => null]);
        });
    }

    /**
     * @param  Collection<int, StockUpload>  $uploads
     */
    private function verifyUploads(WildberriesClient $client, Collection $uploads): void
    {
        $items = StockUploadItem::whereIn('stock_upload_id', $uploads->modelKeys())
            ->with('stock')
            ->get();

        $latestItemIds = $this->getLatestItemIds($items);
        $chrtIds = [];

        foreach ($items as $item) {
            if (! $this->isSuperseded($item, $latestItemIds)) {
                $chrtIds[$item->chrt_id] = $item->chrt_id;
            }
        }

        $wbAmounts = [];
        $wbWarehouseId = $uploads->first()->warehouse->wb_warehouse_id;

        foreach (array_chunk(array_values($chrtIds), WildberriesClient::MAX_STOCKS_PER_REQUEST) as $chunk) {
            if ($this->isTimeBudgetExceeded()) {
                return;
            }

            try {
                $wbAmounts += $client->getStocks($wbWarehouseId, $chunk);
            } catch (RequestException $e) {
                if (! $e->response->clientError()) {
                    throw $e;
                }

                StockUpload::whereKey($uploads->modelKeys())->update([
                    'status' => StockUploadStatus::Unverified,
                    'error' => Str::limit("Чтение остатков отклонено WB ({$e->response->status()}): {$e->response->body()}", 1000),
                ]);

                return;
            }
        }

        $lastOrderAt = $this->getLastOrderTimes($wbWarehouseId, array_values($chrtIds), $uploads->min('created_at'));
        $itemsByUpload = $items->groupBy('stock_upload_id');

        foreach ($uploads as $upload) {
            $this->verifyUpload($upload, $itemsByUpload->get($upload->id, new Collection), $wbAmounts, $lastOrderAt, $latestItemIds);
        }
    }

    /**
     * @param  Collection<int, StockUploadItem>  $items
     * @return array<int, int> stockId => id of its latest upload item that may have reached Wildberries
     */
    private function getLatestItemIds(Collection $items): array
    {
        return StockUploadItem::query()
            ->join('stock_uploads', 'stock_uploads.id', '=', 'stock_upload_items.stock_upload_id')
            ->whereIn('stock_upload_items.stock_id', $items->pluck('stock_id')->filter()->unique()->values())
            ->where('stock_upload_items.id', '>=', (int) $items->min('id'))
            ->where(function (Builder $query) {
                $query->where('stock_uploads.status', '!=', StockUploadStatus::Failed->value)
                    ->orWhereNull('stock_uploads.response_status')
                    ->orWhere('stock_uploads.response_status', '>=', 500);
            })
            ->groupBy('stock_upload_items.stock_id')
            ->selectRaw('stock_upload_items.stock_id, max(stock_upload_items.id) as last_id')
            ->pluck('last_id', 'stock_id')
            ->all();
    }

    /**
     * @param  array<int, int>  $chrtIds
     * @return array<int, string> chrtId => last order time
     */
    private function getLastOrderTimes(int $wbWarehouseId, array $chrtIds, mixed $since): array
    {
        if ($chrtIds === [] || $since === null) {
            return [];
        }

        return Order::query()
            ->where('shop_id', $this->shop->id)
            ->where('warehouse_id', $wbWarehouseId)
            ->whereIn('chrt_id', $chrtIds)
            ->where('wb_created_at', '>=', $since)
            ->groupBy('chrt_id')
            ->selectRaw('chrt_id, max(wb_created_at) as last_order_at')
            ->pluck('last_order_at', 'chrt_id')
            ->all();
    }

    /**
     * @param  Collection<int, StockUploadItem>  $items
     * @param  array<int, int>  $wbAmounts
     * @param  array<int, string>  $lastOrderAt
     * @param  array<int, int>  $latestItemIds
     */
    private function verifyUpload(StockUpload $upload, Collection $items, array $wbAmounts, array $lastOrderAt, array $latestItemIds): void
    {
        $wbAmountsByItemId = [];
        $mismatches = [];
        $missedAmountsByStockId = [];
        $resendAmountsByStockId = [];
        $recoveredStockIds = [];
        $judged = 0;

        foreach ($items as $item) {
            $wbAmount = $wbAmounts[$item->chrt_id] ?? null;

            if ($this->isSuperseded($item, $latestItemIds) || $this->hasOrderSince($item, $upload, $lastOrderAt)) {
                continue;
            }

            $judged++;
            $wbAmountsByItemId[$item->id] = $wbAmount ?? 0;

            if (($wbAmount ?? 0) === $item->amount) {
                if ($item->stock->not_applied_count > 0) {
                    $recoveredStockIds[] = $item->stock_id;
                }

                continue;
            }

            $mismatches[] = ['item' => $item, 'wb_amount' => $wbAmount];

            if ($item->stock->failed_code === null) {
                $missedAmountsByStockId[$item->stock_id] = $item->amount;

                if (($wbAmount ?? 0) > $item->amount) {
                    $resendAmountsByStockId[$item->stock_id] = $item->amount;
                }
            }
        }

        DB::transaction(function () use ($upload, $wbAmountsByItemId, $mismatches, $missedAmountsByStockId, $resendAmountsByStockId, $recoveredStockIds, $judged) {
            if ($wbAmountsByItemId !== []) {
                StockUploadItem::whereKey(array_keys($wbAmountsByItemId))
                    ->update(['wb_amount' => ValuesById::toCase(StockUploadItem::class, $wbAmountsByItemId)]);
            }

            if ($recoveredStockIds !== []) {
                Stock::whereKey($recoveredStockIds)->update(['not_applied_count' => 0, 'not_applied_at' => null]);
            }

            if ($missedAmountsByStockId !== []) {
                Stock::whereKey(array_keys($missedAmountsByStockId))
                    ->where('synced_amount', ValuesById::toCase(Stock::class, $missedAmountsByStockId))
                    ->whereNull('failed_code')
                    ->update([
                        'not_applied_count' => DB::raw('LEAST(not_applied_count + 1, '.self::MAX_NOT_APPLIED_COUNT.')'),
                        'not_applied_at' => now(),
                    ]);
            }

            if ($resendAmountsByStockId !== []) {
                Stock::whereKey(array_keys($resendAmountsByStockId))
                    ->where('synced_amount', ValuesById::toCase(Stock::class, $resendAmountsByStockId))
                    ->whereNull('failed_code')
                    ->update(['synced_amount' => null, 'synced_at' => null]);
            }

            $upload->update([
                'status' => $this->getStatus($judged, $mismatches),
                'verified_at' => $judged > 0 ? now() : null,
                'error' => $mismatches === [] ? null : $this->getMismatchSummary($mismatches),
            ]);
        });
    }

    /**
     * @param  array<int, array{item: StockUploadItem, wb_amount: ?int}>  $mismatches
     */
    private function getStatus(int $judged, array $mismatches): StockUploadStatus
    {
        if ($judged === 0) {
            return StockUploadStatus::Unverified;
        }

        return $mismatches === [] ? StockUploadStatus::Verified : StockUploadStatus::Mismatch;
    }

    /**
     * @param  array<int, int>  $latestItemIds
     */
    private function isSuperseded(StockUploadItem $item, array $latestItemIds): bool
    {
        return $item->stock === null
            || $item->stock->synced_amount !== $item->amount
            || ($latestItemIds[$item->stock_id] ?? $item->id) > $item->id;
    }

    /**
     * @param  array<int, string>  $lastOrderAt
     */
    private function hasOrderSince(StockUploadItem $item, StockUpload $upload, array $lastOrderAt): bool
    {
        $orderAt = $lastOrderAt[$item->chrt_id] ?? null;

        return $orderAt !== null && $upload->created_at->lessThanOrEqualTo($orderAt);
    }

    /**
     * @param  array{item: StockUploadItem, wb_amount: ?int}  $mismatch
     */
    private function getMismatchLine(array $mismatch): string
    {
        $wbAmount = $mismatch['wb_amount'] ?? 'нет';

        return "chrtId {$mismatch['item']->chrt_id}: отправлено {$mismatch['item']->amount}, в WB {$wbAmount}";
    }

    /**
     * @param  array<int, array{item: StockUploadItem, wb_amount: ?int}>  $mismatches
     */
    private function getMismatchSummary(array $mismatches): string
    {
        $parts = [];

        foreach ($mismatches as $mismatch) {
            $parts[] = $this->getMismatchLine($mismatch);
        }

        return Str::limit(count($mismatches).' не совпало. '.implode('; ', $parts), 1000);
    }
}
