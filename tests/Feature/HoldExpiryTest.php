<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test expired holds return stock to product
     */
    public function test_expired_holds_return_stock(): void
    {
        // Arrange
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 90, // Stock after hold was created
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 10,
            'status' => 'active',
            'expires_at' => now()->subMinutes(5), // Expired 5 minutes ago
        ]);

        // Act - Run expiration command
        Artisan::call('holds:expire');

        // Assert
        $product->refresh();
        $hold->refresh();

        // Stock should be returned
        $this->assertEquals(100, $product->stock); // 90 + 10 returned

        // Hold should be marked as expired
        $this->assertEquals('expired', $hold->status);
    }

    /**
     * Test multiple expired holds are processed
     */
    public function test_multiple_expired_holds_are_processed(): void
    {
        // Arrange
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 70,
        ]);

        // Create 3 expired holds
        $hold1 = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'status' => 'active',
            'expires_at' => now()->subMinutes(10),
        ]);

        $hold2 = Hold::create([
            'product_id' => $product->id,
            'quantity' => 10,
            'status' => 'active',
            'expires_at' => now()->subMinutes(5),
        ]);

        $hold3 = Hold::create([
            'product_id' => $product->id,
            'quantity' => 15,
            'status' => 'active',
            'expires_at' => now()->subMinutes(1),
        ]);

        // Act
        Artisan::call('holds:expire');

        // Assert
        $product->refresh();

        // All quantities returned (5 + 10 + 15 = 30)
        $this->assertEquals(100, $product->stock);

        // All holds marked as expired
        $this->assertEquals('expired', $hold1->fresh()->status);
        $this->assertEquals('expired', $hold2->fresh()->status);
        $this->assertEquals('expired', $hold3->fresh()->status);
    }

    /**
     * Test active holds that haven't expired are not processed
     */
    public function test_non_expired_holds_are_not_processed(): void
    {
        // Arrange
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 90,
        ]);

        // Active hold that hasn't expired yet
        $activeHold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 10,
            'status' => 'active',
            'expires_at' => now()->addMinutes(2), // Still valid for 2 minutes
        ]);

        // Act
        Artisan::call('holds:expire');

        // Assert
        $product->refresh();
        $activeHold->refresh();

        // Stock should NOT change
        $this->assertEquals(90, $product->stock);

        // Hold should still be active
        $this->assertEquals('active', $activeHold->status);
    }

    /**
     * Test already expired or used holds are not processed again
     */
    public function test_already_processed_holds_are_skipped(): void
    {
        // Arrange
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 100,
        ]);

        // Already expired hold
        $expiredHold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'status' => 'expired',
            'expires_at' => now()->subMinutes(10),
        ]);

        // Used hold
        $usedHold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 10,
            'status' => 'used',
            'expires_at' => now()->subMinutes(5),
        ]);

        // Act
        Artisan::call('holds:expire');

        // Assert
        $product->refresh();

        // Stock should not change (holds already processed)
        $this->assertEquals(100, $product->stock);

        // Status should remain unchanged
        $this->assertEquals('expired', $expiredHold->fresh()->status);
        $this->assertEquals('used', $usedHold->fresh()->status);
    }

    /**
     * Test command handles empty result gracefully
     */
    public function test_command_handles_no_expired_holds(): void
    {
        // Arrange - No holds in database

        // Act
        $exitCode = Artisan::call('holds:expire');

        // Assert - Command succeeds without errors
        $this->assertEquals(0, $exitCode);
    }

    /**
     * Test expiry doesn't affect different products
     */
    public function test_expiry_affects_correct_products(): void
    {
        // Arrange
        $product1 = Product::create([
            'name' => 'Product 1',
            'description' => 'Test',
            'price' => 99.99,
            'stock' => 50,
        ]);

        $product2 = Product::create([
            'name' => 'Product 2',
            'description' => 'Test',
            'price' => 199.99,
            'stock' => 80,
        ]);

        // Expired hold for product 1
        Hold::create([
            'product_id' => $product1->id,
            'quantity' => 10,
            'status' => 'active',
            'expires_at' => now()->subMinutes(5),
        ]);

        // Active hold for product 2 (not expired)
        Hold::create([
            'product_id' => $product2->id,
            'quantity' => 20,
            'status' => 'active',
            'expires_at' => now()->addMinutes(2),
        ]);

        // Act
        Artisan::call('holds:expire');

        // Assert
        $product1->refresh();
        $product2->refresh();

        // Product 1 stock returned
        $this->assertEquals(60, $product1->stock);

        // Product 2 stock unchanged
        $this->assertEquals(80, $product2->stock);
    }
}
