<?php

namespace App\Console\Commands;

use App\Jobs\VerifyShopStocks;
use App\Models\Shop;
use Illuminate\Console\Command;

class VerifyStocks extends Command
{
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
        $count = 0;

        foreach (Shop::active()->lazyById() as $shop) {
            VerifyShopStocks::dispatch($shop);
            $count++;
        }

        $this->info("Queued stocks check for {$count} shop(s).");

        return self::SUCCESS;
    }
}
