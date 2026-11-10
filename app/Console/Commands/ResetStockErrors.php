<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

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
    protected $description = 'Clear Wildberries rejection marks and not-applied counters of a shop';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $shop = Shop::find($this->argument('shop'));

        if ($shop === null) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        $count = Stock::query()
            ->whereRelation('warehouse', 'shop_id', $shop->id)
            ->where(function (Builder $query) {
                $query->whereNotNull('failed_at')->orWhere('not_applied_count', '>', 0);
            })
            ->update([...Stock::NO_FAILURE, 'not_applied_count' => 0, 'not_applied_at' => null]);

        $this->info("Cleared errors for {$count} stock(s).");

        return self::SUCCESS;
    }
}
