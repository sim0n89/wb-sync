<?php

namespace App\Models;

use Database\Factories\StockUploadItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockUploadItem extends Model
{
    /** @use HasFactory<StockUploadItemFactory> */
    use HasFactory;

    protected $fillable = [
        'stock_upload_id',
        'stock_id',
        'chrt_id',
        'amount',
        'wb_amount',
    ];

    protected function casts(): array
    {
        return [
            'chrt_id' => 'integer',
            'amount' => 'integer',
            'wb_amount' => 'integer',
        ];
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(StockUpload::class, 'stock_upload_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
