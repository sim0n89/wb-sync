<?php

namespace App\Services\Wildberries\Data;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

class StockItemDto extends Data
{
    public function __construct(
        public int $chrtId,
        #[Min(0)]
        public int $amount,
    ) {}
}
