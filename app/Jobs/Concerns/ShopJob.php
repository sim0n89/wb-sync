<?php

namespace App\Jobs\Concerns;

use App\Exceptions\Wildberries\RateLimitExceededException;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

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

    /**
     * @param  iterable<mixed>  $groups
     */
    private function runForEachGroup(iterable $groups, Closure $work): void
    {
        $firstError = null;

        foreach ($groups as $group) {
            if ($this->isTimeBudgetExceeded()) {
                break;
            }

            try {
                $work($group);
            } catch (RateLimitExceededException $e) {
                if ($firstError !== null) {
                    throw $firstError;
                }

                $this->release($e->retryAfter);

                return;
            } catch (Throwable $e) {
                $firstError ??= $e;
            }
        }

        if ($firstError !== null) {
            throw $firstError;
        }
    }

    private function isTimeBudgetExceeded(): bool
    {
        $this->startedAt ??= CarbonImmutable::now();

        return $this->startedAt->diffInSeconds(CarbonImmutable::now()) >= $this->timeBudget;
    }
}
