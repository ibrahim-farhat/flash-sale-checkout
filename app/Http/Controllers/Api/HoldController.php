<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HoldController extends Controller
{
    public function __construct(
        private HoldService $holdService
    ) {}

    /**
     * Create a hold (reserve product temporarily)
     *
     * POST /api/holds
     * Body: { "product_id": 1, "quantity": 2 }
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->holdService->createHold(
            $request->input('product_id'),
            $request->input('quantity')
        );

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], 400);
        }

        return response()->json([
            'data' => [
                'hold_id' => $result['hold_id'],
                'expires_at' => $result['expires_at'],
            ]
        ], 201);
    }
}
