<?php

namespace App\Console\Commands\Concerns;

use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * @mixin Command
 */
trait DispatchesShopJobs
{
    /**
     * @param  class-string<ShouldQueue>  $jobClass
     */
    private function dispatchForActiveShops(string $jobClass, string $label): int
    {
        $count = 0;

        foreach (Shop::active()->lazyById() as $shop) {
            $jobClass::dispatch($shop);
            $count++;
        }

        $this->info("Queued {$label} for {$count} shop(s).");

        return self::SUCCESS;
    }
}
