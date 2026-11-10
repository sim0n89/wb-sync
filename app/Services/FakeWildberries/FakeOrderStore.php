<?php

namespace App\Services\FakeWildberries;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

class FakeOrderStore
{
    public const int FIRST_ORDER_ID = 13_800_000;

    /**
     * Create a new class instance.
     */
    public function __construct(private readonly Repository $cache) {}

    public function addOrders(string $token, int $count): int
    {
        return (int) $this->cache->increment($this->getKey($token), $count);
    }

    public function getTotal(string $token): int
    {
        return (int) $this->cache->get($this->getKey($token), 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrders(string $token, int $next, int $limit): array
    {
        $lastId = self::FIRST_ORDER_ID + $this->getTotal($token);
        $fromId = max($next, self::FIRST_ORDER_ID) + 1;
        $toId = min($fromId + $limit - 1, $lastId);

        $orders = [];

        for ($id = $fromId; $id <= $toId; $id++) {
            $orders[] = $this->getOrder($id);
        }

        return $orders;
    }

    /**
     * @return array<string, mixed>
     */
    private function getOrder(int $id): array
    {
        $number = $id - self::FIRST_ORDER_ID;

        return [
            'id' => $id,
            'rid' => md5("rid-{$id}"),
            'orderUid' => md5("order-{$id}"),
            'article' => 'art-'.($number % 50 + 1),
            'nmId' => 370_000_000 + $number % 50,
            'chrtId' => 12_000_000 + $number % 50,
            'skus' => [(string) (4_600_000_000_000 + $number % 50)],
            'warehouseId' => 658_434,
            'price' => 100_000 + ($number % 50) * 1_000,
            'convertedPrice' => 100_000 + ($number % 50) * 1_000,
            'currencyCode' => 643,
            'convertedCurrencyCode' => 643,
            'deliveryType' => 'fbs',
            'createdAt' => CarbonImmutable::parse('2026-11-01T00:00:00Z')->addMinutes($number * 5)->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function getKey(string $token): string
    {
        return 'fake-wb:orders:'.sha1($token);
    }
}
