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

    public const int FAILED_RETRY_HOURS = 24;

    public const array NO_FAILURE = [
        'failed_amount' => null,
        'failed_at' => null,
        'failed_code' => null,
        'failed_message' => null,
    ];

    protected $fillable = [
        'warehouse_id',
        'product_size_id',
        'amount',
        'synced_amount',
        'synced_at',
        'failed_amount',
        'failed_at',
        'failed_code',
        'failed_message',
        'not_applied_count',
        'not_applied_at',
    ];

    protected $attributes = [
        'amount' => 0,
        'not_applied_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'synced_amount' => 'integer',
            'synced_at' => 'datetime',
            'failed_amount' => 'integer',
            'failed_at' => 'datetime',
            'not_applied_count' => 'integer',
            'not_applied_at' => 'datetime',
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
        })->where(function (Builder $query) {
            $query->whereNull('failed_amount')
                ->orWhereColumn('amount', '!=', 'failed_amount')
                ->orWhere('failed_at', '<=', now()->subHours(self::FAILED_RETRY_HOURS));
        });
    }

    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->whereNotNull('failed_at');
    }
}
