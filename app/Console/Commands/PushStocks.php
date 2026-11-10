<?php

namespace App\Console\Commands;

use App\Jobs\PushShopStocks;
use App\Models\Shop;
use Illuminate\Console\Command;

class PushStocks extends Command
{
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
        $count = 0;

        foreach (Shop::active()->lazyById() as $shop) {
            PushShopStocks::dispatch($shop);
            $count++;
        }

        $this->info("Queued stocks push for {$count} shop(s).");

        return self::SUCCESS;
    }
}
