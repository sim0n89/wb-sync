<?php

namespace App\Services\Wildberries\Data;

use Spatie\LaravelData\Data;

class CardSizeDto extends Data
{
    /**
     * @param  array<int, string>  $skus
     */
    public function __construct(
        public int $chrtId,
        public ?string $techSize = null,
        public array $skus = [],
    ) {}

    public static function fromWildberries(array $size): self
    {
        return new self(
            (int) $size['chrtID'],
            isset($size['techSize']) ? (string) $size['techSize'] : null,
            array_values(array_map('strval', $size['skus'] ?? [])),
        );
    }
}
