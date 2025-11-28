<?php

namespace App\Jobs;

use App\Models\Item;
use App\Services\LinearService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncItemToLinearJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(public Item $item)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(LinearService $linearService): void
    {
        if (! $linearService->isEnabled()) {
            Log::debug('Linear sync disabled, skipping item sync', ['item_id' => $this->item->id]);

            return;
        }

        // Check sync direction
        $syncDirection = config('linear.sync.direction');
        if (! in_array($syncDirection, ['both', 'to_linear'])) {
            Log::debug('Linear sync direction does not allow to_linear', [
                'item_id' => $this->item->id,
                'direction' => $syncDirection,
            ]);

            return;
        }

        try {
            $linearService->syncItemToLinear($this->item);
        } catch (Throwable $e) {
            Log::error('Failed to sync item to Linear', [
                'item_id' => $this->item->id,
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
        Log::error('SyncItemToLinearJob failed permanently', [
            'item_id' => $this->item->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
