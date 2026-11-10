<?php

namespace App\Services\Wildberries\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

class OrderDto extends Data
{
    public function __construct(
        public int $id,
        public int $price,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d\TH:i:sP')]
        public CarbonImmutable $createdAt,
        public ?string $rid = null,
        public ?string $article = null,
        public ?int $nmId = null,
        public ?int $chrtId = null,
        public ?int $warehouseId = null,
        public ?int $currencyCode = null,
        public array $payload = [],
    ) {}

    public static function fromWildberries(array $order): self
    {
        return self::factory()
            ->withoutMagicalCreation()
            ->from([...$order, 'payload' => $order]);
    }
}
