<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessLinearWebhookJob;
use App\Services\LinearWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LinearWebhookController extends Controller
{
    public function __construct(protected LinearWebhookService $webhookService)
    {
    }

    /**
     * Handle incoming Linear webhook
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Linear-Signature', '');

        // Verify webhook signature
        if (! $this->webhookService->verifySignature($payload, $signature)) {
            Log::warning('Linear webhook signature verification failed', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data = $request->json()->all();

        // Dispatch webhook processing to queue
        if (config('linear.sync.auto')) {
            dispatch(new ProcessLinearWebhookJob($data));
        } else {
            Log::debug('Linear webhook received but auto-sync is disabled', [
                'action' => $data['action'] ?? null,
                'type' => $data['type'] ?? null,
            ]);
        }

        return response()->json(['success' => true]);
    }
}
