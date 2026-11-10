<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DispatchesShopJobs;
use App\Jobs\VerifyShopStocks;
use Illuminate\Console\Command;

class VerifyStocks extends Command
{
    use DispatchesShopJobs;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:verify-stocks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue a check that Wildberries really holds the stocks sent to it';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return $this->dispatchForActiveShops(VerifyShopStocks::class, 'stocks check');
    }
}
