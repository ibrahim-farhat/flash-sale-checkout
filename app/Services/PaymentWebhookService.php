<?php

namespace App\Services;

use App\Models\Order;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookService
{
    public function __construct(
        private OrderService $orderService
    ) {}

    /**
     * Process payment webhook with idempotency and out-of-order safety
     *
     * @param string $idempotencyKey Unique key to prevent duplicate processing
     * @param int $orderId Order ID from webhook
     * @param string $paymentStatus 'success' or 'failure'
     * @param array $payload Full webhook payload
     * @return array{success: bool, message: string, already_processed?: bool}
     */
    public function processWebhook(
        string $idempotencyKey,
        int $orderId,
        string $paymentStatus,
        array $payload
    ): array {
        try {
            // CRITICAL: Check if webhook already processed (idempotency)
            $existingLog = WebhookLog::where('idempotency_key', $idempotencyKey)->first();

            if ($existingLog) {
                Log::info('Webhook already processed (duplicate)', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'existing_log_id' => $existingLog->id,
                ]);

                return [
                    'success' => true,
                    'message' => 'Webhook already processed',
                    'already_processed' => true,
                ];
            }

            // Process webhook within transaction for atomicity
            $result = DB::transaction(function () use ($idempotencyKey, $orderId, $paymentStatus, $payload) {
                // Handle race condition: Order might not exist yet
                $order = Order::find($orderId);

                if (!$order) {
                    // Webhook arrived before order creation completed
                    // Log it for retry/manual processing
                    Log::warning('Webhook received before order exists', [
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => $orderId,
                        'payment_status' => $paymentStatus,
                    ]);

                    // Create webhook log without order_id (will be linked later if needed)
                    WebhookLog::create([
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => null, // Order doesn't exist yet
                        'status' => $paymentStatus,
                        'payload' => $payload,
                        'processed_at' => now(),
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Order not found - webhook may have arrived early',
                    ];
                }

                // Create webhook log FIRST (establishes idempotency lock)
                WebhookLog::create([
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $order->id,
                    'status' => $paymentStatus,
                    'payload' => $payload,
                    'processed_at' => now(),
                ]);

                // Process based on payment status
                if ($paymentStatus === 'success') {
                    // Mark order as paid
                    $order->markAsPaid();

                    Log::info('Payment webhook processed - order paid', [
                        'order_id' => $order->id,
                        'idempotency_key' => $idempotencyKey,
                    ]);

                    return [
                        'success' => true,
                        'message' => 'Payment successful, order marked as paid',
                    ];

                } elseif ($paymentStatus === 'failure') {
                    // Payment failed - cancel order and return stock
                    $this->orderService->cancelOrder($order);

                    Log::info('Payment webhook processed - order cancelled, stock returned', [
                        'order_id' => $order->id,
                        'idempotency_key' => $idempotencyKey,
                        'quantity_returned' => $order->quantity,
                    ]);

                    return [
                        'success' => true,
                        'message' => 'Payment failed, order cancelled and stock returned',
                    ];

                } else {
                    throw new \Exception("Invalid payment status: {$paymentStatus}");
                }
            });

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to process webhook', [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to process webhook: ' . $e->getMessage(),
            ];
        }
    }
}
