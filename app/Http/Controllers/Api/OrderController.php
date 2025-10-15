<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService
    ) {}

    /**
     * Create an order from a hold
     *
     * POST /api/orders
     * Body: { "hold_id": 1 }
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hold_id' => 'required|integer|exists:holds,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->orderService->createOrderFromHold($request->input('hold_id'));

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], 400);
        }

        $order = $result['order'];

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'quantity' => $order->quantity,
                'total_price' => $order->total_price,
                'status' => $order->status,
                'created_at' => $order->created_at->toIso8601String(),
            ]
        ], 201);
    }
}
