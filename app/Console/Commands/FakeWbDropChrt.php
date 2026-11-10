<?php

namespace App\Console\Commands;

use App\Services\FakeWildberries\FakeStockStore;
use Illuminate\Console\Command;

class FakeWbDropChrt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fake-wb-drop-chrt {token} {chrt_id} {--off : Save this chrtId again}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make the local Wildberries emulator answer 204 but silently not save a size';

    /**
     * Execute the console command.
     */
    public function handle(FakeStockStore $store): int
    {
        $chrtId = (int) $this->argument('chrt_id');
        $dropped = ! $this->option('off');

        $store->setChrtDropped($this->argument('token'), $chrtId, $dropped);

        $this->info($dropped
            ? "chrtId {$chrtId} will be silently ignored with 204."
            : "chrtId {$chrtId} is saved again.");

        return self::SUCCESS;
    }
}
