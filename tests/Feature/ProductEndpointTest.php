<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test product endpoint returns product details with accurate stock
     */
    public function test_can_view_product_details(): void
    {
        // Arrange: Create a test product
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test Description',
            'price' => 999.99,
            'stock' => 100,
        ]);

        // Act: Request product details
        $response = $this->getJson("/api/products/{$product->id}");

        // Assert: Check response structure and data
        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $product->id,
                    'name' => 'Test Product',
                    'description' => 'Test Description',
                    'price' => '999.99',
                    'available_stock' => 100,
                    'in_stock' => true,
                ]
            ]);
    }

    /**
     * Test product endpoint returns 404 for non-existent product
     */
    public function test_returns_404_for_non_existent_product(): void
    {
        $response = $this->getJson('/api/products/999');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Product not found'
            ]);
    }

    /**
     * Test product data is cached in Redis
     */
    public function test_product_data_is_cached(): void
    {
        // Arrange
        $product = Product::create([
            'name' => 'Cached Product',
            'description' => 'Test caching',
            'price' => 199.99,
            'stock' => 50,
        ]);

        // Clear cache before test
        Cache::forget('product:' . $product->id);

        // Act: First request (cache miss)
        $this->getJson("/api/products/{$product->id}")->assertStatus(200);

        // Assert: Cache should now contain product data
        $this->assertTrue(Cache::has('product:' . $product->id));

        // Act: Second request (should hit cache)
        $response = $this->getJson("/api/products/{$product->id}");

        // Assert: Should return same data from cache
        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Cached Product');
    }

    /**
     * Test product shows correct in_stock status
     */
    public function test_shows_correct_stock_status(): void
    {
        // Product with stock
        $inStockProduct = Product::create([
            'name' => 'In Stock Product',
            'description' => 'Available',
            'price' => 99.99,
            'stock' => 10,
        ]);

        // Product without stock
        $outOfStockProduct = Product::create([
            'name' => 'Out of Stock Product',
            'description' => 'Not available',
            'price' => 99.99,
            'stock' => 0,
        ]);

        // Assert in stock
        $this->getJson("/api/products/{$inStockProduct->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.in_stock', true);

        // Assert out of stock
        $this->getJson("/api/products/{$outOfStockProduct->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.in_stock', false);
    }
}
