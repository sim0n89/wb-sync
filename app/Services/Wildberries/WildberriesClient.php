<?php

namespace App\Services\Wildberries;

use App\Exceptions\Wildberries\RateLimitExceededException;
use App\Models\Shop;
use App\Services\Wildberries\Data\OrdersPageDto;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

class WildberriesClient
{
    public const int MAX_LIMIT = 1000;

    /**
     * Create a new class instance.
     */
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
        private readonly int $requestsPerSecond = 5,
    ) {}

    public static function forShop(Shop $shop): self
    {
        return new self(
            $shop->wb_token,
            config('services.wildberries.marketplace_url'),
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

        $response = $this->send('/api/v3/orders', $query);

        return OrdersPageDto::fromWildberries($response->json());
    }

    private function send(string $path, array $query): Response
    {
        $this->waitForRateLimit();

        $response = $this->getRequest()->get($path, $query);

        if ($response->status() === 429) {
            throw new RateLimitExceededException($this->getRetryAfter($response));
        }

        return $response->throw();
    }

    private function waitForRateLimit(): void
    {
        $key = 'wildberries:'.sha1($this->token);

        while (RateLimiter::tooManyAttempts($key, $this->requestsPerSecond)) {
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
