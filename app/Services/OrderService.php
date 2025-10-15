<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    /**
     * Create an order from a hold
     *
     * @param int $holdId
     * @return array{success: bool, order_id?: int, order?: Order, message?: string}
     */
    public function createOrderFromHold(int $holdId): array
    {
        try {
            $order = DB::transaction(function () use ($holdId) {
                // Get hold with product relationship
                $hold = Hold::with('product')->find($holdId);

                if (!$hold) {
                    throw new \Exception('Hold not found');
                }

                // Validate hold is active and not expired
                if ($hold->status !== 'active') {
                    throw new \Exception("Hold is {$hold->status} and cannot be used");
                }

                if ($hold->isExpired()) {
                    throw new \Exception('Hold has expired');
                }

                // Check if hold has already been used (via unique constraint on orders.hold_id)
                if (Order::where('hold_id', $holdId)->exists()) {
                    throw new \Exception('Hold has already been used for an order');
                }

                // Calculate total price
                $totalPrice = $hold->product->price * $hold->quantity;

                // Create order
                $order = Order::create([
                    'hold_id' => $hold->id,
                    'product_id' => $hold->product_id,
                    'quantity' => $hold->quantity,
                    'total_price' => $totalPrice,
                    'status' => 'pending',
                ]);

                // Mark hold as used
                $hold->markAsUsed();

                Log::info('Order created from hold', [
                    'order_id' => $order->id,
                    'hold_id' => $hold->id,
                    'product_id' => $hold->product_id,
                    'quantity' => $hold->quantity,
                    'total_price' => $totalPrice,
                ]);

                return $order;
            });

            return [
                'success' => true,
                'order_id' => $order->id,
                'order' => $order,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create order from hold', [
                'hold_id' => $holdId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get order by ID
     */
    public function getOrder(int $orderId): ?Order
    {
        return Order::with(['product', 'hold'])->find($orderId);
    }

    /**
     * Cancel an order and return stock
     */
    public function cancelOrder(Order $order): bool
    {
        if ($order->status !== 'pending') {
            Log::warning('Attempted to cancel non-pending order', [
                'order_id' => $order->id,
                'status' => $order->status,
            ]);
            return false;
        }

        try {
            DB::transaction(function () use ($order) {
                // Return stock to product
                $product = $order->product;
                $product->increment('stock', $order->quantity);

                // Mark order as cancelled
                $order->markAsCancelled();

                Log::info('Order cancelled and stock returned', [
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                    'quantity' => $order->quantity,
                ]);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cancel order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}