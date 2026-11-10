<?php

namespace App\Services\Wildberries\Data;

use Spatie\LaravelData\Data;

class CardsPageDto extends Data
{
    /**
     * @param  array<int, CardDto>  $cards
     */
    public function __construct(
        public array $cards,
        public ?string $cursorUpdatedAt,
        public ?int $cursorNmId,
        public int $total,
    ) {}

    public static function fromWildberries(array $response): self
    {
        $cards = [];

        foreach ($response['cards'] ?? [] as $card) {
            $cards[] = CardDto::fromWildberries($card);
        }

        $cursor = $response['cursor'] ?? [];

        return new self(
            $cards,
            isset($cursor['updatedAt']) ? (string) $cursor['updatedAt'] : null,
            isset($cursor['nmID']) ? (int) $cursor['nmID'] : null,
            (int) ($cursor['total'] ?? 0),
        );
    }

    public function isLast(int $limit): bool
    {
        return $this->total < $limit || $this->cursorNmId === null;
    }
}
