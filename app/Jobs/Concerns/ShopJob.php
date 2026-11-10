<?php

namespace App\Jobs\Concerns;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

trait ShopJob
{
    public const int BUSY_RELEASE_SECONDS = 60;

    public int $tries = 20;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public int $uniqueFor = 900;

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
        $sharedKey = $this->getSharedOverlapKey();

        $overlap = (new WithoutOverlapping($sharedKey ?? (string) $this->getShop()->id))
            ->expireAfter($this->timeout + 60);

        if ($sharedKey !== null) {
            $overlap->shared();
        }

        if ($this->shouldWaitWhenBusy()) {
            return [$overlap->releaseAfter(self::BUSY_RELEASE_SECONDS)];
        }

        return [$overlap->dontRelease()];
    }

    protected function getSharedOverlapKey(): ?string
    {
        return null;
    }

    protected function shouldWaitWhenBusy(): bool
    {
        return false;
    }

    private function isTimeBudgetExceeded(): bool
    {
        $this->startedAt ??= CarbonImmutable::now();

        return $this->startedAt->diffInSeconds(CarbonImmutable::now()) >= $this->timeBudget;
    }
}
