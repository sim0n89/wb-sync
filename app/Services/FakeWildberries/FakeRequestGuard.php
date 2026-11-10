<?php

namespace App\Services\FakeWildberries;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class FakeRequestGuard
{
    public function getRejection(Request $request): ?JsonResponse
    {
        $token = (string) $request->header('Authorization');

        if ($token === '') {
            return $this->getError(401, 'unauthorized', 'empty Authorization header');
        }

        $rateLimit = (int) config('services.wildberries.fake_rate_limit');

        if ($rateLimit < 1) {
            return null;
        }

        $rateKey = 'fake-wb:rate:'.sha1($token);

        if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
            $retry = max(1, RateLimiter::availableIn($rateKey));

            return $this->getError(429, 'too many requests', 'limited by fake rate limit')->withHeaders([
                'X-Ratelimit-Retry' => $retry,
                'X-Ratelimit-Limit' => $rateLimit,
                'X-Ratelimit-Reset' => $retry,
            ]);
        }

        RateLimiter::hit($rateKey, 1);

        return null;
    }

    public function getStocksError(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([['code' => $code, 'message' => $message]], $status);
    }

    public function getError(int $status, string $title, string $detail): JsonResponse
    {
        return response()->json([
            'title' => $title,
            'detail' => $detail,
            'status' => $status,
        ], $status);
    }
}
