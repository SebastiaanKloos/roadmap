<?php

namespace App\Services;

use App\Models\Item;
use App\Models\LinearSyncLog;
use App\Models\LinearSyncMapping;
use App\Jobs\SyncItemFromLinearJob;
use Illuminate\Support\Facades\Log;

class LinearWebhookService
{
    protected LinearService $linearService;

    public function __construct(LinearService $linearService)
    {
        $this->linearService = $linearService;
    }

    /**
     * Verify webhook signature
     */
    public function verifySignature(string $payload, string $signature): bool
    {
        $secret = config('linear.webhook.secret');

        if (empty($secret)) {
            // If no secret configured, skip verification (not recommended for production)
            Log::warning('Linear webhook secret not configured, skipping signature verification');

            return true;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Process incoming webhook payload
     */
    public function processWebhook(array $payload): void
    {
        $action = $payload['action'] ?? null;
        $type = $payload['type'] ?? null;

        if ($type !== 'Issue') {
            Log::debug('Linear webhook skipped (not an Issue event)', ['type' => $type]);

            return;
        }

        match ($action) {
            'create' => $this->processIssueCreated($payload),
            'update' => $this->processIssueUpdated($payload),
            'remove' => $this->processIssueDeleted($payload),
            default => Log::debug('Unknown Linear webhook action', ['action' => $action]),
        };
    }

    /**
     * Process issue created event
     */
    public function processIssueCreated(array $payload): void
    {
        $issue = $payload['data'] ?? null;

        if (! $issue) {
            Log::warning('Linear webhook: No issue data in payload');

            return;
        }

        // Check if issue should be synced based on labels
        if (! $this->linearService->shouldSyncFromLinear($issue)) {
            LinearSyncLog::logSkipped(
                null,
                $issue['id'] ?? null,
                'create',
                'Linear issue skipped (missing required labels)',
                [
                    'labels' => $issue['labels'] ?? [],
                    'required_labels' => config('linear.filtering.labels'),
                ]
            );

            return;
        }

        // Check if already synced
        $existingMapping = LinearSyncMapping::where('linear_id', $issue['id'])->first();

        if ($existingMapping) {
            Log::debug('Linear issue already synced', ['linear_id' => $issue['id']]);

            return;
        }

        // Dispatch sync job
        dispatch(new SyncItemFromLinearJob($issue['id']));

        Log::info('Linear issue create webhook processed', ['linear_id' => $issue['id']]);
    }

    /**
     * Process issue updated event
     */
    public function processIssueUpdated(array $payload): void
    {
        $issue = $payload['data'] ?? null;
        $updatedFrom = $payload['updatedFrom'] ?? [];

        if (! $issue) {
            Log::warning('Linear webhook: No issue data in payload');

            return;
        }

        $linearId = $issue['id'];

        // Check if labels changed and issue should now be synced/unsynced
        $shouldSync = $this->linearService->shouldSyncFromLinear($issue);
        $existingMapping = LinearSyncMapping::where('linear_id', $linearId)->first();

        if ($shouldSync && ! $existingMapping) {
            // Issue now has required labels, create new sync
            dispatch(new SyncItemFromLinearJob($linearId));
            Log::info('Linear issue now has required labels, syncing', ['linear_id' => $linearId]);

            return;
        }

        if (! $shouldSync && $existingMapping) {
            // Labels removed, handle unsync
            $this->handleUnsyncedIssue($existingMapping);
            Log::info('Linear issue labels removed, unsyncing', ['linear_id' => $linearId]);

            return;
        }

        if ($shouldSync && $existingMapping) {
            // Update existing sync
            dispatch(new SyncItemFromLinearJob($linearId));
            Log::info('Linear issue updated, re-syncing', ['linear_id' => $linearId]);
        }
    }

    /**
     * Process issue deleted/archived event
     */
    public function processIssueDeleted(array $payload): void
    {
        $issue = $payload['data'] ?? null;

        if (! $issue) {
            Log::warning('Linear webhook: No issue data in payload');

            return;
        }

        $linearId = $issue['id'];

        $mapping = LinearSyncMapping::where('linear_id', $linearId)->first();

        if (! $mapping) {
            Log::debug('Linear issue delete webhook: No mapping found', ['linear_id' => $linearId]);

            return;
        }

        $this->handleUnsyncedIssue($mapping);

        Log::info('Linear issue deleted webhook processed', ['linear_id' => $linearId]);
    }

    /**
     * Handle an issue that should be unsynced
     * Options: delete the roadmap item, or just remove the mapping
     */
    protected function handleUnsyncedIssue(LinearSyncMapping $mapping): void
    {
        $item = $mapping->item;

        // Option 1: Just remove the mapping (keep the roadmap item)
        $mapping->delete();

        // Clear Linear fields from item
        $item->update([
            'linear_id' => null,
            'linear_url' => null,
        ]);

        LinearSyncLog::logSuccess(
            $item->id,
            $mapping->linear_id,
            'delete',
            'from_linear',
            'Linear sync mapping removed (item kept)'
        );

        // Option 2 (commented out): Delete the roadmap item entirely
        // $item->delete();
        // LinearSyncLog::logSuccess(
        //     null,
        //     $mapping->linear_id,
        //     'delete',
        //     'from_linear',
        //     'Roadmap item deleted due to Linear issue removal'
        // );
    }
}
