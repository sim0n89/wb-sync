<?php

namespace App\Console\Commands;

use App\Services\FakeWildberries\FakeStockStore;
use Illuminate\Console\Command;

class FakeWbStocks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fake-wb-stocks {token} {wb_warehouse_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show stocks stored in the local Wildberries emulator';

    /**
     * Execute the console command.
     */
    public function handle(FakeStockStore $store): int
    {
        $rows = [];

        foreach ($store->getStocks($this->argument('token'), (int) $this->argument('wb_warehouse_id')) as $chrtId => $amount) {
            $rows[] = [$chrtId, $amount];
        }

        if ($rows === []) {
            $this->info('Emulator has no stocks for this warehouse.');

            return self::SUCCESS;
        }

        $this->table(['chrtId', 'amount'], $rows);

        return self::SUCCESS;
    }
}
