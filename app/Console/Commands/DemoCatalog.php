<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoCatalog extends Command
{
    public const array SIZES = ['S', 'M', 'L'];

    public const array WAREHOUSES = [
        507 => 'Склад Краснодар',
        508 => 'Склад Ростов',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:demo-catalog {shop : Shop id} {--products=5 : How many products to create}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create demo warehouses, products, sizes and stocks for a shop';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $shop = Shop::findOrFail((int) $this->argument('shop'));
        $productsCount = (int) $this->option('products');

        DB::transaction(function () use ($shop, $productsCount) {
            $warehouses = [];

            foreach (self::WAREHOUSES as $wbWarehouseId => $name) {
                $warehouses[] = Warehouse::firstOrCreate(
                    ['shop_id' => $shop->id, 'wb_warehouse_id' => $wbWarehouseId],
                    ['name' => $name],
                );
            }

            $nextChrtId = max(40_000_000, (int) ProductSize::max('chrt_id')) + 1;
            $nextNmId = max(500_000_000, (int) Product::max('nm_id')) + 1;

            for ($i = 0; $i < $productsCount; $i++) {
                $product = Product::create([
                    'shop_id' => $shop->id,
                    'nm_id' => $nextNmId,
                    'vendor_code' => "demo-{$nextNmId}",
                    'name' => "Демо-футболка {$nextNmId}",
                ]);
                $nextNmId++;

                foreach (self::SIZES as $techSize) {
                    $size = ProductSize::create([
                        'product_id' => $product->id,
                        'chrt_id' => $nextChrtId,
                        'tech_size' => $techSize,
                    ]);
                    $size->barcodes()->create(['barcode' => (string) (4_600_000_000_000 + $nextChrtId)]);
                    $nextChrtId++;

                    foreach ($warehouses as $warehouse) {
                        Stock::create([
                            'warehouse_id' => $warehouse->id,
                            'product_size_id' => $size->id,
                            'amount' => random_int(0, 50),
                        ]);
                    }
                }
            }
        });

        $sizes = $productsCount * count(self::SIZES);

        $this->info("Created {$productsCount} product(s), {$sizes} size(s), stocks on ".count(self::WAREHOUSES).' warehouse(s).');

        return self::SUCCESS;
    }
}
