<?php

namespace App\Console\Commands;

use App\Services\FakeWildberries\FakeStockStore;
use Illuminate\Console\Command;

class FakeWbBlockWarehouse extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fake-wb-block-warehouse {token} {wb_warehouse_id} {--off : Unblock the warehouse}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make the local Wildberries emulator answer 406 for a warehouse';

    /**
     * Execute the console command.
     */
    public function handle(FakeStockStore $store): int
    {
        $warehouseId = (int) $this->argument('wb_warehouse_id');
        $blocked = ! $this->option('off');

        $store->setWarehouseBlocked($this->argument('token'), $warehouseId, $blocked);

        $this->info($blocked
            ? "Warehouse {$warehouseId} is blocked with 406."
            : "Warehouse {$warehouseId} is open again.");

        return self::SUCCESS;
    }
}
