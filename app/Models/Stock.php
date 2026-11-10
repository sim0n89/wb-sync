<?php

namespace App\Models;

use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'product_size_id',
        'amount',
        'synced_amount',
        'synced_at',
    ];

    protected $attributes = [
        'amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'synced_amount' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    #[Scope]
    protected function needsSync(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->whereNull('synced_amount')
                ->orWhereColumn('amount', '!=', 'synced_amount');
        });
    }
}
