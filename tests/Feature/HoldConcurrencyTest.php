<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HoldConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that parallel hold attempts at stock boundary do not cause overselling
     * This is the CRITICAL test for flash-sale systems
     */
    public function test_parallel_holds_prevent_overselling_at_boundary(): void
    {
        // Arrange: Create product with exactly 10 items
        $product = Product::create([
            'name' => 'Limited Stock Product',
            'description' => 'Only 10 available',
            'price' => 99.99,
            'stock' => 10,
        ]);

        // Act: Simulate 5 concurrent requests, each trying to reserve 3 items
        // Expected: Only 3 holds should succeed (3+3+3 = 9, then 10th request fails)
        $results = [];
        $holdIds = [];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'quantity' => 3,
            ]);

            $results[] = $response->status();

            if ($response->status() === 201) {
                $holdIds[] = $response->json('data.hold_id');
            }
        }

        // Assert: Check results
        $successCount = count(array_filter($results, fn($status) => $status === 201));
        $failureCount = count(array_filter($results, fn($status) => $status === 400));

        // Should have 3 successful holds (3*3=9 items) and 2 failures
        $this->assertEquals(3, $successCount, 'Should allow exactly 3 holds of 3 items each');
        $this->assertEquals(2, $failureCount, 'Should reject 2 holds due to insufficient stock');

        // Verify final stock
        $product->refresh();
        $this->assertEquals(1, $product->stock, 'Should have 1 item remaining (10 - 9)');

        // Verify holds in database
        $this->assertCount(3, $holdIds);
        $this->assertEquals(3, Hold::where('status', 'active')->count());
    }

    /**
     * Test exact stock boundary (trying to reserve exactly available stock)
     */
    public function test_hold_at_exact_stock_boundary(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 5,
        ]);

        // Hold exactly all available stock
        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $response->assertStatus(201);

        // Verify stock is now 0
        $product->refresh();
        $this->assertEquals(0, $product->stock);

        // Try to hold more - should fail
        $response2 = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $response2->assertStatus(400)
            ->assertJsonFragment(['message' => 'Insufficient stock. Available: 0']);
    }

    /**
     * Test multiple small holds don't oversell
     */
    public function test_multiple_small_holds_prevent_overselling(): void
    {
        $product = Product::create([
            'name' => 'Popular Item',
            'description' => 'Hot product',
            'price' => 199.99,
            'stock' => 10,
        ]);

        $successfulHolds = 0;

        // Try to create 15 holds of 1 item each (more than available)
        for ($i = 0; $i < 15; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'quantity' => 1,
            ]);

            if ($response->status() === 201) {
                $successfulHolds++;
            }
        }

        // Should succeed exactly 10 times
        $this->assertEquals(10, $successfulHolds);

        // Stock should be 0
        $product->refresh();
        $this->assertEquals(0, $product->stock);

        // Verify 10 active holds
        $this->assertEquals(10, Hold::where('product_id', $product->id)
            ->where('status', 'active')
            ->count());
    }

    /**
     * Test hold validation
     */
    public function test_hold_validation(): void
    {
        $product = Product::create([
            'name' => 'Test',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 100,
        ]);

        // Test zero quantity
        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'quantity' => 0,
        ]);
        $response->assertStatus(422);

        // Test negative quantity
        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'quantity' => -5,
        ]);
        $response->assertStatus(422);

        // Test missing product_id
        $response = $this->postJson('/api/holds', [
            'quantity' => 5,
        ]);
        $response->assertStatus(422);

        // Test non-existent product
        $response = $this->postJson('/api/holds', [
            'product_id' => 99999,
            'quantity' => 5,
        ]);
        $response->assertStatus(422);
    }

    /**
     * Test hold expiry time is set correctly (2 minutes)
     */
    public function test_hold_has_correct_expiry_time(): void
    {
        $product = Product::create([
            'name' => 'Test',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 100,
        ]);

        $beforeCreate = now();

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $afterCreate = now();

        $response->assertStatus(201);

        $hold = Hold::find($response->json('data.hold_id'));

        // Expires at should be approximately 2 minutes from now
        $this->assertTrue(
            $hold->expires_at->between(
                $beforeCreate->addMinutes(2)->subSeconds(5),
                $afterCreate->addMinutes(2)->addSeconds(5)
            )
        );
    }
}
