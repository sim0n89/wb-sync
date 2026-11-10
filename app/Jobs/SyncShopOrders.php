<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Shop;
use App\Services\Wildberries\Data\OrdersPageDto;
use App\Services\Wildberries\WildberriesClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncShopOrders implements ShouldQueue
{
    use Queueable;

    public const int MAX_PAGES = 100;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(public Shop $shop) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->shop->is_active) {
            return;
        }

        $client = WildberriesClient::forShop($this->shop);

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $ordersPage = $client->getOrders(next: $this->shop->orders_cursor);

            if ($ordersPage->isEmpty()) {
                break;
            }

            Order::upsert(
                $this->getOrderRows($ordersPage),
                ['shop_id', 'wb_order_id'],
                ['rid', 'article', 'nm_id', 'chrt_id', 'warehouse_id', 'price', 'currency_code', 'wb_created_at', 'payload'],
            );

            if ($ordersPage->next === $this->shop->orders_cursor) {
                break;
            }

            $this->shop->update(['orders_cursor' => $ordersPage->next]);
        }

        $this->shop->update(['orders_synced_at' => now()]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getOrderRows(OrdersPageDto $ordersPage): array
    {
        $rows = [];

        foreach ($ordersPage->orders as $order) {
            $rows[] = [
                'shop_id' => $this->shop->id,
                'wb_order_id' => $order->id,
                'rid' => $order->rid,
                'article' => $order->article,
                'nm_id' => $order->nmId,
                'chrt_id' => $order->chrtId,
                'warehouse_id' => $order->warehouseId,
                'price' => $order->price,
                'currency_code' => $order->currencyCode,
                'wb_created_at' => $order->createdAt->utc()->toDateTimeString(),
                'payload' => json_encode($order->payload, JSON_UNESCAPED_UNICODE),
            ];
        }

        return $rows;
    }
}
