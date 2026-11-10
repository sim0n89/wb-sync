<?php

namespace App\Http\Controllers\FakeWildberries;

use App\Http\Controllers\Controller;
use App\Services\FakeWildberries\FakeRequestGuard;
use App\Services\FakeWildberries\FakeStockStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StocksController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, int $warehouseId, FakeStockStore $store, FakeRequestGuard $guard): JsonResponse|Response
    {
        $rejection = $guard->getRejection($request);

        if ($rejection !== null) {
            return $rejection;
        }

        $token = (string) $request->header('Authorization');
        $stocks = $request->input('stocks');

        if (! is_array($stocks) || $stocks === [] || count($stocks) > 1000) {
            return $this->getRejection(400, 'IncorrectRequestBody', 'Incorrect required body');
        }

        if ($store->isWarehouseBlocked($token, $warehouseId)) {
            return $this->getRejection(406, 'WarehouseStocksUpdateBlock', 'The warehouse is processing');
        }

        $amounts = [];

        foreach ($stocks as $item) {
            if (is_array($item) && is_int($item['chrtId'] ?? null) && is_int($item['amount'] ?? null)) {
                $amounts[$item['chrtId']] = $item['amount'];
            }
        }

        $rejectedResponse = $this->getRejectedItems($store->getRejections($token), $amounts);

        if ($rejectedResponse !== null) {
            return $rejectedResponse;
        }

        $store->saveStocks($token, $warehouseId, $amounts);

        return response()->noContent();
    }

    /**
     * @param  array<int, array{code: string, list: bool}>  $rejections
     * @param  array<int, int>  $amounts
     */
    private function getRejectedItems(array $rejections, array $amounts): ?JsonResponse
    {
        $errors = [];

        foreach ($amounts as $chrtId => $amount) {
            if (! isset($rejections[$chrtId])) {
                continue;
            }

            $code = $rejections[$chrtId]['code'];
            $errors[$code] ??= ['data' => [], 'code' => $code, 'message' => "Fake rejection: {$code}"];

            if ($rejections[$chrtId]['list']) {
                $errors[$code]['data'][] = ['chrtId' => $chrtId, 'amount' => $amount];
            }
        }

        if ($errors === []) {
            return null;
        }

        return response()->json(array_values($errors), 409);
    }

    private function getRejection(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([['code' => $code, 'message' => $message]], $status);
    }
}
