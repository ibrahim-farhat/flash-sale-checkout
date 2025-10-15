<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test successful payment webhook marks order as paid
     */
    public function test_successful_payment_marks_order_as_paid(): void
    {
        // Arrange
        [$product, $order] = $this->createOrderFixture();

        // Act
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'test-key-123',
            'order_id' => $order->id,
            'payment_status' => 'success',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Payment successful, order marked as paid',
                'already_processed' => false,
            ]);

        $order->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertNotNull($order->paid_at);

        // Verify webhook log created
        $this->assertDatabaseHas('webhook_logs', [
            'idempotency_key' => 'test-key-123',
            'order_id' => $order->id,
            'status' => 'success',
        ]);
    }

    /**
     * Test failed payment webhook cancels order and returns stock
     */
    public function test_failed_payment_cancels_order_and_returns_stock(): void
    {
        // Arrange
        [$product, $order] = $this->createOrderFixture();
        $initialStock = $product->stock;

        // Act
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'test-key-456',
            'order_id' => $order->id,
            'payment_status' => 'failure',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Payment failed, order cancelled and stock returned',
            ]);

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);

        // Verify stock returned
        $product->refresh();
        $this->assertEquals($initialStock + $order->quantity, $product->stock);
    }

    /**
     * Test webhook idempotency - same key processed only once
     */
    public function test_webhook_idempotency_prevents_duplicate_processing(): void
    {
        // Arrange
        [$product, $order] = $this->createOrderFixture();

        // Act - Send webhook first time
        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'duplicate-key-789',
            'order_id' => $order->id,
            'payment_status' => 'success',
        ]);

        // Send same webhook again (duplicate)
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'duplicate-key-789',
            'order_id' => $order->id,
            'payment_status' => 'success',
        ]);

        // Assert
        $response1->assertStatus(200);
        $response2->assertStatus(200)
            ->assertJson([
                'message' => 'Webhook already processed',
                'already_processed' => true,
            ]);

        // Verify only one webhook log
        $this->assertEquals(1, WebhookLog::where('idempotency_key', 'duplicate-key-789')->count());

        // Order should still be paid (not double-processed)
        $order->refresh();
        $this->assertEquals('paid', $order->status);
    }

    /**
     * Test webhook can be sent multiple times with same key (retries)
     */
    public function test_webhook_handles_multiple_retries_with_same_key(): void
    {
        // Arrange
        [$product, $order] = $this->createOrderFixture();
        $idempotencyKey = 'retry-key-999';

        // Act - Send webhook 5 times
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/payments/webhook', [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $order->id,
                'payment_status' => 'success',
            ]);

            $response->assertStatus(200);
        }

        // Assert - Only processed once
        $this->assertEquals(1, WebhookLog::where('idempotency_key', $idempotencyKey)->count());

        $order->refresh();
        $this->assertEquals('paid', $order->status);
    }

    /**
     * Test webhook arriving before order exists (race condition)
     */
    public function test_webhook_handles_race_condition_when_order_not_found(): void
    {
        // Act - Send webhook for non-existent order
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'early-webhook-123',
            'order_id' => 99999,
            'payment_status' => 'success',
        ]);

        // Assert
        $response->assertStatus(400)
            ->assertJsonFragment([
                'message' => 'Order not found - webhook may have arrived early',
            ]);

        // Webhook should still be logged
        $this->assertDatabaseHas('webhook_logs', [
            'idempotency_key' => 'early-webhook-123',
            'order_id' => null,
            'status' => 'success',
        ]);
    }

    /**
     * Test webhook validation
     */
    public function test_webhook_validation(): void
    {
        // Missing idempotency_key
        $response = $this->postJson('/api/payments/webhook', [
            'order_id' => 1,
            'payment_status' => 'success',
        ]);
        $response->assertStatus(422);

        // Missing order_id
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'test',
            'payment_status' => 'success',
        ]);
        $response->assertStatus(422);

        // Invalid payment_status
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'test',
            'order_id' => 1,
            'payment_status' => 'invalid',
        ]);
        $response->assertStatus(422);
    }

    /**
     * Test webhook stores full payload for debugging
     */
    public function test_webhook_stores_full_payload(): void
    {
        // Arrange
        [$product, $order] = $this->createOrderFixture();

        // Act
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'payload-test',
            'order_id' => $order->id,
            'payment_status' => 'success',
            'extra_data' => 'some_value',
            'payment_method' => 'credit_card',
        ]);

        // Assert - Full payload stored
        $log = WebhookLog::where('idempotency_key', 'payload-test')->first();
        $this->assertNotNull($log);
        $this->assertEquals('some_value', $log->payload['extra_data']);
        $this->assertEquals('credit_card', $log->payload['payment_method']);
    }

    /**
     * Helper: Create product, hold, and order
     */
    private function createOrderFixture(): array
    {
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 90, // Stock already reduced by hold
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'status' => 'used',
            'expires_at' => now()->addMinutes(2),
        ]);

        $order = Order::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'total_price' => 499.95,
            'status' => 'pending',
        ]);

        return [$product, $order];
    }
}