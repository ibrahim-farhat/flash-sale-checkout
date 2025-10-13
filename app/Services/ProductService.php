<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductService
{
    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_PREFIX = 'product:';

    /**
     * Get product by ID with caching
     */
    public function getProduct(int $productId): ?Product
    {
        $cacheKey = self::CACHE_PREFIX . $productId;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($productId) {
            Log::info('Product cache miss', ['product_id' => $productId]);
            return Product::find($productId);
        });
    }

    /**
     * Get product with fresh data (bypass cache)
     */
    public function getProductFresh(int $productId): ?Product
    {
        return Product::find($productId);
    }

    /**
     * Invalidate product cache
     */
    public function invalidateCache(int $productId): void
    {
        $cacheKey = self::CACHE_PREFIX . $productId;
        Cache::forget($cacheKey);
        Log::info('Product cache invalidated', ['product_id' => $productId]);
    }

    /**
     * Get product with accurate stock (for API response)
     */
    public function getProductDetails(int $productId): ?array
    {
        $product = $this->getProduct($productId);

        if (!$product) {
            return null;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $product->price,
            'available_stock' => $product->available_stock,
            'in_stock' => $product->stock > 0,
        ];
    }
}
