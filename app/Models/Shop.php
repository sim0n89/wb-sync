<?php

namespace App\Models;

use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'wb_token',
        'is_active',
        'orders_cursor',
        'orders_synced_at',
    ];

    protected $attributes = [
        'is_active' => true,
        'orders_cursor' => 0,
    ];

    protected $hidden = [
        'wb_token',
    ];

    protected function casts(): array
    {
        return [
            'wb_token' => 'encrypted',
            'is_active' => 'boolean',
            'orders_cursor' => 'integer',
            'orders_synced_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
