<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'stock',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    /**
     * Get available stock (current stock is already available)
     */
    public function getAvailableStockAttribute(): int
    {
        return $this->stock;
    }

    /**
     * Check if product has enough stock
     */
    public function hasStock(int $quantity): bool
    {
        return $this->stock >= $quantity;
    }
}
