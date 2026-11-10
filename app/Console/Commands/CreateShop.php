<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

class CreateShop extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-shop {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add a Wildberries shop with its API token';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $token = $this->secret('Wildberries API token');

        if (blank($token)) {
            $this->error('Token is required.');

            return self::FAILURE;
        }

        $shop = Shop::create([
            'name' => $this->argument('name'),
            'wb_token' => $token,
        ]);

        $this->info("Shop #{$shop->id} created.");

        return self::SUCCESS;
    }
}
