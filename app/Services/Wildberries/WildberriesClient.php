<?php

namespace App\Services\Wildberries;

use App\Models\Shop;
use App\Services\Wildberries\Data\OrdersPageDto;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WildberriesClient
{
    public const int MAX_LIMIT = 1000;

    /**
     * Create a new class instance.
     */
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
    ) {}

    public static function forShop(Shop $shop): self
    {
        return new self($shop->wb_token, config('services.wildberries.marketplace_url'));
    }

    public function getOrders(int $next = 0, int $limit = self::MAX_LIMIT, ?CarbonInterface $from = null, ?CarbonInterface $to = null): OrdersPageDto
    {
        $query = [
            'limit' => min($limit, self::MAX_LIMIT),
            'next' => $next,
        ];

        if ($from !== null) {
            $query['dateFrom'] = $from->getTimestamp();
        }

        if ($to !== null) {
            $query['dateTo'] = $to->getTimestamp();
        }

        $response = $this->getRequest()
            ->get('/api/v3/orders', $query)
            ->throw()
            ->json();

        return OrdersPageDto::fromWildberries($response);
    }

    private function getRequest(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['Authorization' => $this->token])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }
}
