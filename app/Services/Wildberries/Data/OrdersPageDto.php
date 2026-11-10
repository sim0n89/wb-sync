<?php

namespace App\Services\Wildberries\Data;

use Spatie\LaravelData\Data;

class OrdersPageDto extends Data
{
    /**
     * @param  array<int, OrderDto>  $orders
     */
    public function __construct(
        public array $orders,
        public int $next,
    ) {}

    public static function fromWildberries(array $response): self
    {
        $orders = [];

        foreach ($response['orders'] ?? [] as $order) {
            $orders[] = OrderDto::fromWildberries($order);
        }

        return new self($orders, (int) ($response['next'] ?? 0));
    }

    public function isEmpty(): bool
    {
        return $this->orders === [];
    }
}
