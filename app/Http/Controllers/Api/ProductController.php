<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {}

    /**
     * Display the specified product with accurate available stock
     *
     * GET /api/products/{id}
     */
    public function show(int $id): JsonResponse
    {
        $product = $this->productService->getProductDetails($id);

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'data' => $product
        ], 200);
    }
}
