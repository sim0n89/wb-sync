<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DispatchesShopJobs;
use App\Jobs\SyncShopOrders;
use Illuminate\Console\Command;

class SyncOrders extends Command
{
    use DispatchesShopJobs;

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
        return $this->dispatchForActiveShops(SyncShopOrders::class, 'orders sync');
    }
}
