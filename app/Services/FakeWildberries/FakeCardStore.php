<?php

namespace App\Services\FakeWildberries;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

class FakeCardStore
{
    public const int FIRST_NM_ID = 500_000_000;

    public const int FIRST_CHRT_ID = 40_000_000;

    public const array SIZES = ['S', 'M', 'L'];

    /**
     * Create a new class instance.
     */
    public function __construct(private readonly Repository $cache) {}

    /**
     * @return int how many cards the token has now
     */
    public function addCards(string $token, int $count): int
    {
        $cards = $this->getUpdatedAt($token);
        $next = count($cards) + 1;
        $updatedAt = $this->now();

        for ($i = 0; $i < $count; $i++, $next++) {
            $cards[self::FIRST_NM_ID + $next] = $updatedAt;
        }

        $this->save($token, $cards);

        return count($cards);
    }

    public function hasCard(string $token, int $nmId): bool
    {
        return array_key_exists($nmId, $this->getUpdatedAt($token));
    }

    public function setTrashed(string $token, int $nmId, bool $trashed): void
    {
        $key = $this->getKey($token, 'trash');
        $trashedIds = $this->cache->get($key, []);

        if ($trashed) {
            $trashedIds[$nmId] = true;
        } else {
            unset($trashedIds[$nmId]);
        }

        $this->cache->forever($key, $trashedIds);

        $cards = $this->getUpdatedAt($token);
        $cards[$nmId] = $this->now();
        $this->save($token, $cards);
    }

    /**
     * Cards outside the trash sorted by updatedAt and nmID, after the cursor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCards(string $token, ?string $afterUpdatedAt, ?int $afterNmId, int $limit): array
    {
        $trashed = $this->cache->get($this->getKey($token, 'trash'), []);
        $rows = [];

        foreach ($this->getUpdatedAt($token) as $nmId => $updatedAt) {
            if (isset($trashed[$nmId])) {
                continue;
            }

            if ($afterUpdatedAt !== null && [$updatedAt, $nmId] <= [$afterUpdatedAt, (int) $afterNmId]) {
                continue;
            }

            $rows[] = [$updatedAt, $nmId];
        }

        sort($rows);

        return array_map(
            fn (array $row) => $this->getCard($row[1], $row[0]),
            array_slice($rows, 0, $limit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getCard(int $nmId, string $updatedAt): array
    {
        $number = $nmId - self::FIRST_NM_ID;
        $sizes = [];

        foreach (self::SIZES as $i => $techSize) {
            $chrtId = self::FIRST_CHRT_ID + ($number - 1) * count(self::SIZES) + $i + 1;

            $sizes[] = [
                'chrtID' => $chrtId,
                'techSize' => $techSize,
                'skus' => [(string) (4_600_000_000_000 + $chrtId)],
            ];
        }

        return [
            'nmID' => $nmId,
            'vendorCode' => "demo-{$nmId}",
            'title' => "Демо-футболка {$nmId}",
            'sizes' => $sizes,
            'updatedAt' => $updatedAt,
        ];
    }

    /**
     * @return array<int, string> nmID => updatedAt
     */
    private function getUpdatedAt(string $token): array
    {
        return $this->cache->get($this->getKey($token, 'cards'), []);
    }

    /**
     * @param  array<int, string>  $cards
     */
    private function save(string $token, array $cards): void
    {
        $this->cache->forever($this->getKey($token, 'cards'), $cards);
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z');
    }

    private function getKey(string $token, string $name): string
    {
        return 'fake-wb:'.sha1($token).':'.$name;
    }
}
