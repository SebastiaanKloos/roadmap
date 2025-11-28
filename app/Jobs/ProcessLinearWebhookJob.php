<?php

namespace App\Jobs;

use App\Services\LinearWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessLinearWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $payload)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(LinearWebhookService $webhookService): void
    {
        try {
            $webhookService->processWebhook($this->payload);
        } catch (Throwable $e) {
            Log::error('Failed to process Linear webhook', [
                'payload' => $this->payload,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessLinearWebhookJob failed permanently', [
            'payload' => $this->payload,
            'error' => $exception?->getMessage(),
        ]);
    }
}
