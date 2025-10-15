<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentWebhookService $webhookService
    ) {}

    /**
     * Handle payment webhook (idempotent)
     *
     * POST /api/payments/webhook
     * Body: {
     *   "idempotency_key": "unique-key-123",
     *   "order_id": 1,
     *   "payment_status": "success" or "failure"
     * }
     */
    public function handle(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'idempotency_key' => 'required|string|max:255',
            'order_id' => 'required|integer',
            'payment_status' => 'required|in:success,failure',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->webhookService->processWebhook(
            $request->input('idempotency_key'),
            $request->input('order_id'),
            $request->input('payment_status'),
            $request->all() // Full payload for logging
        );

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], 400);
        }

        return response()->json([
            'message' => $result['message'],
            'already_processed' => $result['already_processed'] ?? false,
        ], 200);
    }
}
