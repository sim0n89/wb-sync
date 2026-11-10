<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DispatchesShopJobs;
use App\Jobs\PushShopStocks;
use Illuminate\Console\Command;

class PushStocks extends Command
{
    use DispatchesShopJobs;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:push-stocks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue stocks push for every active shop';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return $this->dispatchForActiveShops(PushShopStocks::class, 'stocks push');
    }
}
