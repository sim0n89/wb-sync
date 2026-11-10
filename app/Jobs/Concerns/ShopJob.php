<?php

namespace App\Jobs\Concerns;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

trait ShopJob
{
    public int $tries = 20;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public int $timeBudget = 240;

    private ?CarbonImmutable $startedAt = null;

    abstract public function getShop(): Shop;

    public function uniqueId(): string
    {
        return (string) $this->getShop()->id;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->getShop()->id))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    private function isTimeBudgetExceeded(): bool
    {
        $this->startedAt ??= CarbonImmutable::now();

        return $this->startedAt->diffInSeconds(CarbonImmutable::now()) >= $this->timeBudget;
    }
}
