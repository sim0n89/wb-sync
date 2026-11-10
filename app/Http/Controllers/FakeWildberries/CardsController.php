<?php

namespace App\Http\Controllers\FakeWildberries;

use App\Http\Controllers\Controller;
use App\Services\FakeWildberries\FakeCardStore;
use App\Services\FakeWildberries\FakeRequestGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, FakeCardStore $store, FakeRequestGuard $guard): JsonResponse
    {
        $rejection = $guard->getRejection($request);

        if ($rejection !== null) {
            return $rejection;
        }

        $cursor = $request->input('settings.cursor');
        $limit = is_array($cursor) ? ($cursor['limit'] ?? null) : null;

        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            return $this->getError('Неверный settings.cursor.limit: от 1 до 100');
        }

        $updatedAt = $cursor['updatedAt'] ?? null;
        $nmId = $cursor['nmID'] ?? null;

        if (($updatedAt !== null || $nmId !== null) && (! is_string($updatedAt) || ! is_int($nmId))) {
            return $this->getError('Курсор должен содержать updatedAt и nmID вместе');
        }

        $cards = $store->getCards(
            (string) $request->header('Authorization'),
            $updatedAt,
            $nmId,
            $limit,
        );

        $last = end($cards) ?: null;

        return response()->json([
            'cards' => $cards,
            'cursor' => [
                'updatedAt' => $last['updatedAt'] ?? $updatedAt,
                'nmID' => $last['nmID'] ?? $nmId,
                'total' => count($cards),
            ],
        ]);
    }

    private function getError(string $text): JsonResponse
    {
        return response()->json([
            'data' => null,
            'error' => true,
            'errorText' => $text,
            'additionalErrors' => null,
        ], 400);
    }
}
