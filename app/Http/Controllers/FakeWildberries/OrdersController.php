<?php

namespace App\Http\Controllers\FakeWildberries;

use App\Http\Controllers\Controller;
use App\Services\FakeWildberries\FakeOrderStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class OrdersController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, FakeOrderStore $store): JsonResponse
    {
        $token = (string) $request->header('Authorization');

        if ($token === '') {
            return $this->getError(401, 'unauthorized', 'empty Authorization header');
        }

        $rateLimit = (int) config('services.wildberries.fake_rate_limit');
        $rateKey = 'fake-wb:rate:'.sha1($token);

        if ($rateLimit > 0) {
            if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
                return $this->getError(429, 'too many requests', 'limited by fake rate limit')->withHeaders([
                    'X-Ratelimit-Retry' => max(1, RateLimiter::availableIn($rateKey)),
                    'X-Ratelimit-Limit' => $rateLimit,
                    'X-Ratelimit-Reset' => max(1, RateLimiter::availableIn($rateKey)),
                ]);
            }

            RateLimiter::hit($rateKey, 1);
        }

        $limit = $request->integer('limit');
        $next = $request->integer('next', -1);

        if ($limit < 1 || $limit > 1000 || $next < 0) {
            return $this->getError(400, 'bad request', 'limit must be 1..1000 and next must be set');
        }

        $orders = $store->getOrders($token, $next, $limit);

        return response()->json([
            'next' => $orders === [] ? $next : $orders[array_key_last($orders)]['id'],
            'orders' => $orders,
        ]);
    }

    private function getError(int $status, string $title, string $detail): JsonResponse
    {
        return response()->json([
            'title' => $title,
            'detail' => $detail,
            'status' => $status,
        ], $status);
    }
}
