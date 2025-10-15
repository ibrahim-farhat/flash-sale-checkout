<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test creating an order from a valid hold
     */
    public function test_can_create_order_from_valid_hold(): void
    {
        // Arrange
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 100,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'status' => 'active',
            'expires_at' => now()->addMinutes(2),
        ]);

        // Act
        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        // Assert
        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'order_id',
                    'product_id',
                    'quantity',
                    'total_price',
                    'status',
                    'created_at',
                ]
            ])
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.total_price', '499.95') // 99.99 * 5
            ->assertJsonPath('data.status', 'pending');

        // Verify order created in database
        $this->assertDatabaseHas('orders', [
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'status' => 'pending',
        ]);

        // Verify hold is marked as used
        $hold->refresh();
        $this->assertEquals('used', $hold->status);
    }

    /**
     * Test cannot use the same hold twice
     */
    public function test_cannot_use_hold_twice(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 100,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'status' => 'active',
            'expires_at' => now()->addMinutes(2),
        ]);

        // Create first order
        $response1 = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);
        $response1->assertStatus(201);

        // Try to create second order with same hold
        $response2 = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response2->assertStatus(400)
            ->assertJson([
                'message' => 'Hold is used and cannot be used',
            ]);

        // Verify only one order exists
        $this->assertEquals(1, Order::count());
    }

    /**
     * Test cannot create order from expired hold
     */
    public function test_cannot_create_order_from_expired_hold(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 100,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'status' => 'active',
            'expires_at' => now()->subMinutes(5), // Expired 5 minutes ago
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Hold has expired',
            ]);

        // Verify no order was created
        $this->assertEquals(0, Order::count());
    }

    /**
     * Test cannot create order from non-active hold
     */
    public function test_cannot_create_order_from_non_active_hold(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 100,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'status' => 'expired', // Already expired
            'expires_at' => now()->addMinutes(2),
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Hold is expired and cannot be used',
            ]);

        // Verify no order was created
        $this->assertEquals(0, Order::count());
    }

    /**
     * Test order validation
     */
    public function test_order_validation(): void
    {
        // Missing hold_id
        $response = $this->postJson('/api/orders', []);
        $response->assertStatus(422);

        // Invalid hold_id (doesn't exist)
        $response = $this->postJson('/api/orders', [
            'hold_id' => 99999,
        ]);
        $response->assertStatus(422);
    }

    /**
     * Test order calculates correct total price
     */
    public function test_order_calculates_correct_total_price(): void
    {
        $product = Product::create([
            'name' => 'Expensive Item',
            'description' => 'Test',
            'price' => 1234.56,
            'stock' => 100,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 3,
            'status' => 'active',
            'expires_at' => now()->addMinutes(2),
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $expectedTotal = 1234.56 * 3; // 3703.68

        $response->assertStatus(201)
            ->assertJsonPath('data.total_price', number_format($expectedTotal, 2, '.', ''));
    }

    /**
     * Test order status is pending by default
     */
    public function test_order_status_is_pending_by_default(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 100,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->addMinutes(2),
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');

        $order = Order::first();
        $this->assertEquals('pending', $order->status);
        $this->assertNull($order->paid_at);
    }
}
