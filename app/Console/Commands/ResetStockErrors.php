<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Illuminate\Console\Command;

class ResetStockErrors extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reset-stock-errors {shop : Shop id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear Wildberries rejection marks so the stocks are pushed again';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = Stock::failed()
            ->whereRelation('warehouse', 'shop_id', (int) $this->argument('shop'))
            ->update([
                'failed_amount' => null,
                'failed_at' => null,
                'failed_code' => null,
                'failed_message' => null,
            ]);

        $this->info("Cleared errors for {$count} stock(s).");

        return self::SUCCESS;
    }
}
