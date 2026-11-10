<?php

namespace App\Console\Commands;

use App\Services\FakeWildberries\FakeCardStore;
use Illuminate\Console\Command;

class FakeWbTrashCard extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fake-wb-trash-card {token} {nm_id} {--off : Restore the card from the trash}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move a product card to the trash in the local Wildberries emulator so it leaves the cards list';

    /**
     * Execute the console command.
     */
    public function handle(FakeCardStore $store): int
    {
        $token = $this->argument('token');
        $nmId = (int) $this->argument('nm_id');

        if (! $store->hasCard($token, $nmId)) {
            $this->error("Card {$nmId} does not exist in the emulator.");

            return self::FAILURE;
        }

        $trashed = ! $this->option('off');
        $store->setTrashed($token, $nmId, $trashed);

        $this->info($trashed ? "Card {$nmId} is in the trash." : "Card {$nmId} is restored.");

        return self::SUCCESS;
    }
}
