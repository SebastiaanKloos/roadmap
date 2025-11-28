<?php

namespace App\Jobs;

use App\Services\LinearService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncItemFromLinearJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $linearId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(LinearService $linearService): void
    {
        if (! $linearService->isEnabled()) {
            Log::debug('Linear sync disabled, skipping Linear issue sync', ['linear_id' => $this->linearId]);

            return;
        }

        // Check sync direction
        $syncDirection = config('linear.sync.direction');
        if (! in_array($syncDirection, ['both', 'from_linear'])) {
            Log::debug('Linear sync direction does not allow from_linear', [
                'linear_id' => $this->linearId,
                'direction' => $syncDirection,
            ]);

            return;
        }

        try {
            $linearService->syncItemFromLinear($this->linearId);
        } catch (Throwable $e) {
            Log::error('Failed to sync item from Linear', [
                'linear_id' => $this->linearId,
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
        Log::error('SyncItemFromLinearJob failed permanently', [
            'linear_id' => $this->linearId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
