<?php

namespace App\Http\Controllers\FakeWildberries;

use App\Http\Controllers\Controller;
use App\Services\FakeWildberries\FakeRequestGuard;
use App\Services\FakeWildberries\FakeStockStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StocksReadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, int $warehouseId, FakeStockStore $store, FakeRequestGuard $guard): JsonResponse
    {
        $rejection = $guard->getRejection($request);

        if ($rejection !== null) {
            return $rejection;
        }

        $chrtIds = $request->input('chrtIds');

        if (! is_array($chrtIds) || $chrtIds === [] || count($chrtIds) > 1000) {
            return $guard->getStocksError(400, 'IncorrectRequestBody', 'Incorrect required body');
        }

        $stored = $store->getStocks((string) $request->header('Authorization'), $warehouseId);
        $stocks = [];

        foreach ($chrtIds as $chrtId) {
            if (is_int($chrtId) && array_key_exists($chrtId, $stored)) {
                $stocks[] = ['chrtId' => $chrtId, 'amount' => $stored[$chrtId]];
            }
        }

        return response()->json(['stocks' => $stocks]);
    }
}
