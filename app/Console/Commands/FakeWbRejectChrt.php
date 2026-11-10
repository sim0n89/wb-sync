<?php

namespace App\Console\Commands;

use App\Services\FakeWildberries\FakeStockStore;
use Illuminate\Console\Command;

class FakeWbRejectChrt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fake-wb-reject-chrt {token} {chrt_id} {code=TnvedRequired}
        {--hide-ids : Do not list rejected chrtId in the error data}
        {--off : Stop rejecting this chrtId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make the local Wildberries emulator reject stocks of a size with 409';

    /**
     * Execute the console command.
     */
    public function handle(FakeStockStore $store): int
    {
        $token = $this->argument('token');
        $chrtId = (int) $this->argument('chrt_id');

        if ($this->option('off')) {
            $store->acceptChrt($token, $chrtId);
            $this->info("chrtId {$chrtId} is accepted again.");

            return self::SUCCESS;
        }

        $store->rejectChrt($token, $chrtId, $this->argument('code'), ! $this->option('hide-ids'));
        $this->info("chrtId {$chrtId} will be rejected with 409 {$this->argument('code')}.");

        return self::SUCCESS;
    }
}
