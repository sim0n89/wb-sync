<?php

namespace App\Services\Wildberries\Data;

use Spatie\LaravelData\Data;

class CardDto extends Data
{
    /**
     * @param  array<int, CardSizeDto>  $sizes
     */
    public function __construct(
        public int $nmId,
        public string $vendorCode,
        public string $title,
        public array $sizes,
    ) {}

    public static function fromWildberries(array $card): self
    {
        $sizes = [];

        foreach ($card['sizes'] ?? [] as $size) {
            $sizes[] = CardSizeDto::fromWildberries($size);
        }

        return new self(
            (int) $card['nmID'],
            (string) $card['vendorCode'],
            (string) ($card['title'] ?? ''),
            $sizes,
        );
    }
}
