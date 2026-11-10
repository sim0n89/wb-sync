<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Illuminate\Console\Command;

class SetStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:set-stock {chrt_id : Size chrtId} {wb_warehouse_id : Wildberries warehouse id} {amount : New amount}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Change stock amount of a size on a warehouse';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $amount = (int) $this->argument('amount');

        if ($amount < 0) {
            $this->error('Amount cannot be negative.');

            return self::FAILURE;
        }

        $stock = Stock::whereRelation('size', 'chrt_id', (int) $this->argument('chrt_id'))
            ->whereRelation('warehouse', 'wb_warehouse_id', (int) $this->argument('wb_warehouse_id'))
            ->first();

        if ($stock === null) {
            $this->error('Stock not found.');

            return self::FAILURE;
        }

        $stock->update(['amount' => $amount]);

        $this->info("Stock #{$stock->id} amount is {$amount} now.");

        return self::SUCCESS;
    }
}
