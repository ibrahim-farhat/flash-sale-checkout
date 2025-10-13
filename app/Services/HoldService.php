<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HoldService
{
    private const HOLD_EXPIRY_MINUTES = 2;

    public function __construct(
        private ProductService $productService
    ) {}

    /**
     * Create a hold for a product with pessimistic locking to prevent overselling
     *
     * @param int $productId
     * @param int $quantity
     * @return array{success: bool, hold_id?: int, expires_at?: string, message?: string}
     */
    public function createHold(int $productId, int $quantity): array
    {
        if ($quantity <= 0) {
            return [
                'success' => false,
                'message' => 'Quantity must be greater than 0'
            ];
        }

        try {
            $hold = DB::transaction(function () use ($productId, $quantity) {
                // CRITICAL: Lock the product row for update (pessimistic locking)
                // Other concurrent requests will WAIT here until this transaction completes
                $product = Product::where('id', $productId)
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw new \Exception('Product not found');
                }

                // Check if enough stock is available
                if ($product->stock < $quantity) {
                    Log::warning('Insufficient stock for hold', [
                        'product_id' => $productId,
                        'requested' => $quantity,
                        'available' => $product->stock,
                    ]);

                    throw new \Exception("Insufficient stock. Available: {$product->stock}");
                }

                // Decrease stock atomically
                $product->decrement('stock', $quantity);

                // Create hold record
                $expiresAt = now()->addMinutes(self::HOLD_EXPIRY_MINUTES);

                $hold = Hold::create([
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'status' => 'active',
                    'expires_at' => $expiresAt,
                ]);

                Log::info('Hold created successfully', [
                    'hold_id' => $hold->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'expires_at' => $expiresAt,
                    'remaining_stock' => $product->fresh()->stock,
                ]);

                // Invalidate product cache to reflect new stock
                $this->productService->invalidateCache($productId);

                return $hold;
            });

            return [
                'success' => true,
                'hold_id' => $hold->id,
                'expires_at' => $hold->expires_at->toIso8601String(),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create hold', [
                'product_id' => $productId,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get hold by ID
     */
    public function getHold(int $holdId): ?Hold
    {
        return Hold::find($holdId);
    }

    /**
     * Release an expired hold (return stock)
     */
    public function releaseExpiredHold(Hold $hold): bool
    {
        if ($hold->status !== 'active') {
            return false; // Already processed
        }

        try {
            DB::transaction(function () use ($hold) {
                // Lock product for update
                $product = Product::where('id', $hold->product_id)
                    ->lockForUpdate()
                    ->first();

                // Return stock
                $product->increment('stock', $hold->quantity);

                // Mark hold as expired
                $hold->markAsExpired();

                Log::info('Hold expired and stock released', [
                    'hold_id' => $hold->id,
                    'product_id' => $hold->product_id,
                    'quantity' => $hold->quantity,
                ]);

                // Invalidate cache
                $this->productService->invalidateCache($hold->product_id);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to release expired hold', [
                'hold_id' => $hold->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
