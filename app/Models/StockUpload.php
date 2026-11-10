<?php

namespace App\Models;

use App\Enums\StockUploadStatus;
use Database\Factories\StockUploadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockUpload extends Model
{
    /** @use HasFactory<StockUploadFactory> */
    use HasFactory;

    use MassPrunable;

    protected $fillable = [
        'shop_id',
        'warehouse_id',
        'status',
        'items_count',
        'response_status',
        'error',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockUploadStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockUploadItem::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('services.wildberries.history_days')));
    }
}
