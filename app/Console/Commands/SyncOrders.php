<?php

namespace App\Console\Commands;

use App\Jobs\SyncShopOrders;
use App\Models\Shop;
use Illuminate\Console\Command;

class SyncOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue orders sync for every active shop';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;

        foreach (Shop::active()->lazyById() as $shop) {
            SyncShopOrders::dispatch($shop);
            $count++;
        }

        $this->info("Queued orders sync for {$count} shop(s).");

        return self::SUCCESS;
    }
}
