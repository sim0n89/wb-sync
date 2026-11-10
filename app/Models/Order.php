<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'wb_order_id',
        'rid',
        'article',
        'nm_id',
        'chrt_id',
        'warehouse_id',
        'price',
        'currency_code',
        'wb_created_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'wb_created_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
