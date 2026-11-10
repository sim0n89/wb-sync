<?php

namespace App\Services\Wildberries;

use App\Exceptions\Wildberries\RateLimitExceededException;
use App\Exceptions\Wildberries\StocksRejectedException;
use App\Models\Shop;
use App\Services\Wildberries\Data\CardsPageDto;
use App\Services\Wildberries\Data\OrdersPageDto;
use App\Services\Wildberries\Data\StockItemDto;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

class WildberriesClient
{
    public const int MAX_LIMIT = 1000;

    public const int MAX_STOCKS_PER_REQUEST = 1000;

    public const int MAX_CARDS_PER_PAGE = 100;

    public const int CONTENT_REQUESTS_PER_SECOND = 1;

    /**
     * Create a new class instance.
     */
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
        private readonly string $contentUrl,
        private readonly int $requestsPerSecond = 5,
    ) {}

    public static function forShop(Shop $shop): self
    {
        return new self(
            $shop->wb_token,
            config('services.wildberries.marketplace_url'),
            config('services.wildberries.content_url'),
            (int) config('services.wildberries.requests_per_second'),
        );
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

        $response = $this->send('GET', '/api/v3/orders', ['query' => $query]);

        return OrdersPageDto::fromWildberries($response->json());
    }

    /**
     * @param  array<int, StockItemDto>  $items
     */
    public function updateStocks(int $wbWarehouseId, array $items): void
    {
        if ($items === [] || count($items) > self::MAX_STOCKS_PER_REQUEST) {
            throw new InvalidArgumentException('Wildberries accepts from 1 to '.self::MAX_STOCKS_PER_REQUEST.' stocks per request.');
        }

        $stocks = [];

        foreach ($items as $item) {
            $stocks[] = [
                'chrtId' => $item->chrtId,
                'amount' => $item->amount,
            ];
        }

        try {
            $this->send('PUT', "/api/v3/stocks/{$wbWarehouseId}", ['json' => ['stocks' => $stocks]]);
        } catch (RequestException $e) {
            if (StocksRejectedException::isRejection($e->response)) {
                throw StocksRejectedException::fromResponse($e->response, $e);
            }

            throw $e;
        }
    }

    /**
     * @param  array<int, int>  $chrtIds
     * @return array<int, int> chrtId => amount, sizes unknown to Wildberries are absent
     */
    public function getStocks(int $wbWarehouseId, array $chrtIds): array
    {
        if ($chrtIds === [] || count($chrtIds) > self::MAX_STOCKS_PER_REQUEST) {
            throw new InvalidArgumentException('Wildberries accepts from 1 to '.self::MAX_STOCKS_PER_REQUEST.' sizes per request.');
        }

        $response = $this->send('POST', "/api/v3/stocks/{$wbWarehouseId}", ['json' => ['chrtIds' => array_values($chrtIds)]]);

        $amounts = [];

        foreach ($response->json('stocks') ?? [] as $stock) {
            $amounts[(int) $stock['chrtId']] = (int) $stock['amount'];
        }

        return $amounts;
    }

    public function getCards(?string $cursorUpdatedAt = null, ?int $cursorNmId = null, int $limit = self::MAX_CARDS_PER_PAGE): CardsPageDto
    {
        $cursor = ['limit' => min($limit, self::MAX_CARDS_PER_PAGE)];

        if ($cursorUpdatedAt !== null && $cursorNmId !== null) {
            $cursor['updatedAt'] = $cursorUpdatedAt;
            $cursor['nmID'] = $cursorNmId;
        }

        $response = $this->sendToContent('POST', '/content/v2/get/cards/list', ['json' => [
            'settings' => [
                'sort' => ['ascending' => true],
                'filter' => ['withPhoto' => -1],
                'cursor' => $cursor,
            ],
        ]]);

        return CardsPageDto::fromWildberries($response->json());
    }

    private function send(string $method, string $path, array $options): Response
    {
        $this->waitForRateLimit('wildberries', $this->requestsPerSecond);

        return $this->sendRequest($method, $path, $options);
    }

    private function sendToContent(string $method, string $path, array $options): Response
    {
        $this->waitForRateLimit('wildberries-content', self::CONTENT_REQUESTS_PER_SECOND);

        return $this->sendRequest($method, rtrim($this->contentUrl, '/').$path, $options);
    }

    private function sendRequest(string $method, string $url, array $options): Response
    {
        $response = $this->getRequest()->send($method, $url, $options);

        if ($response->status() === 429) {
            throw new RateLimitExceededException($this->getRetryAfter($response));
        }

        return $response->throw();
    }

    private function waitForRateLimit(string $bucket, int $requestsPerSecond): void
    {
        $key = $bucket.':'.sha1($this->token);

        while (RateLimiter::tooManyAttempts($key, $requestsPerSecond)) {
            Sleep::for(max(1, RateLimiter::availableIn($key)))->seconds();
        }

        RateLimiter::hit($key, 1);
    }

    private function getRetryAfter(Response $response): int
    {
        $retryAfter = (int) $response->header('X-Ratelimit-Retry');

        return $retryAfter > 0 ? $retryAfter : RateLimitExceededException::DEFAULT_RETRY_AFTER;
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
