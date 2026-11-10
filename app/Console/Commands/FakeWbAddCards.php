<?php

namespace App\Console\Commands;

use App\Services\FakeWildberries\FakeCardStore;
use Illuminate\Console\Command;

class FakeWbAddCards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fake-wb-add-cards {token} {count : How many product cards to add}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add product cards with three sizes each to the local Wildberries emulator';

    /**
     * Execute the console command.
     */
    public function handle(FakeCardStore $store): int
    {
        $count = (int) $this->argument('count');

        if ($count < 1) {
            $this->error('Count must be positive.');

            return self::FAILURE;
        }

        $total = $store->addCards($this->argument('token'), $count);

        $this->info("Added {$count} card(s), {$total} in total for this token.");

        return self::SUCCESS;
    }
}
