<?php

namespace App\Http\Controllers\FakeWildberries;

use App\Http\Controllers\Controller;
use App\Services\FakeWildberries\FakeOrderStore;
use App\Services\FakeWildberries\FakeRequestGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, FakeOrderStore $store, FakeRequestGuard $guard): JsonResponse
    {
        $rejection = $guard->getRejection($request);

        if ($rejection !== null) {
            return $rejection;
        }

        $limit = $request->integer('limit');
        $next = $request->integer('next', -1);

        if ($limit < 1 || $limit > 1000 || $next < 0) {
            return $guard->getError(400, 'bad request', 'limit must be 1..1000 and next must be set');
        }

        $orders = $store->getOrders((string) $request->header('Authorization'), $next, $limit);

        return response()->json([
            'next' => $orders === [] ? $next : $orders[array_key_last($orders)]['id'],
            'orders' => $orders,
        ]);
    }
}
