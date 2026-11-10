<?php

namespace App\Console\Commands;

use App\Services\FakeWildberries\FakeOrderStore;
use Illuminate\Console\Command;

class FakeWbAddOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fake-wb-add-orders {token} {count=10}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add generated orders to the local Wildberries emulator';

    /**
     * Execute the console command.
     */
    public function handle(FakeOrderStore $store): int
    {
        $count = (int) $this->argument('count');

        if ($count < 1) {
            $this->error('Count must be positive.');

            return self::FAILURE;
        }

        $total = $store->addOrders($this->argument('token'), $count);

        $this->info("Added {$count} order(s), {$total} in total for this token.");

        return self::SUCCESS;
    }
}
