<?php

namespace App\Services\FakeWildberries;

use Illuminate\Contracts\Cache\Repository;

class FakeStockStore
{
    /**
     * Create a new class instance.
     */
    public function __construct(private readonly Repository $cache) {}

    /**
     * @param  array<int, int>  $amounts  chrtId => amount
     */
    public function saveStocks(string $token, int $warehouseId, array $amounts): void
    {
        $key = $this->getKey($token, "stocks:{$warehouseId}");
        $stored = $this->cache->get($key, []);

        foreach ($amounts as $chrtId => $amount) {
            $stored[$chrtId] = $amount;
        }

        ksort($stored);
        $this->cache->forever($key, $stored);
    }

    /**
     * @return array<int, int> chrtId => amount
     */
    public function getStocks(string $token, int $warehouseId): array
    {
        return $this->cache->get($this->getKey($token, "stocks:{$warehouseId}"), []);
    }

    public function rejectChrt(string $token, int $chrtId, string $code, bool $listInData): void
    {
        $rejections = $this->getRejections($token);
        $rejections[$chrtId] = ['code' => $code, 'list' => $listInData];

        $this->cache->forever($this->getKey($token, 'rejections'), $rejections);
    }

    public function acceptChrt(string $token, int $chrtId): void
    {
        $rejections = $this->getRejections($token);
        unset($rejections[$chrtId]);

        $this->cache->forever($this->getKey($token, 'rejections'), $rejections);
    }

    /**
     * @return array<int, array{code: string, list: bool}>
     */
    public function getRejections(string $token): array
    {
        return $this->cache->get($this->getKey($token, 'rejections'), []);
    }

    public function setWarehouseBlocked(string $token, int $warehouseId, bool $blocked): void
    {
        $key = $this->getKey($token, 'blocked');
        $blockedIds = $this->cache->get($key, []);

        if ($blocked) {
            $blockedIds[$warehouseId] = true;
        } else {
            unset($blockedIds[$warehouseId]);
        }

        $this->cache->forever($key, $blockedIds);
    }

    public function isWarehouseBlocked(string $token, int $warehouseId): bool
    {
        return isset($this->cache->get($this->getKey($token, 'blocked'), [])[$warehouseId]);
    }

    private function getKey(string $token, string $name): string
    {
        return 'fake-wb:'.sha1($token).':'.$name;
    }
}
