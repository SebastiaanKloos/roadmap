<?php

namespace App\Console\Commands\Linear;

use App\Models\Item;
use App\Services\LinearService;
use Illuminate\Console\Command;

class SyncItemCommand extends Command
{
    protected $signature = 'linear:sync-item {item-id : The ID of the roadmap item to sync}';

    protected $description = 'Sync a specific roadmap item to Linear';

    public function handle(LinearService $linearService): int
    {
        $itemId = $this->argument('item-id');

        $item = Item::find($itemId);

        if (! $item) {
            $this->error("Item with ID {$itemId} not found.");

            return self::FAILURE;
        }

        $this->info("Syncing item #{$item->id}: {$item->title}");

        try {
            $mapping = $linearService->syncItemToLinear($item);

            if ($mapping) {
                $this->info('✓ Item synced successfully to Linear');
                $this->line("  Linear ID: {$mapping->linear_id}");
                $this->line("  Linear Identifier: {$mapping->linear_identifier}");
                if ($item->linear_url) {
                    $this->line("  Linear URL: {$item->linear_url}");
                }

                return self::SUCCESS;
            }

            $this->error('✗ Failed to sync item to Linear');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('✗ Error syncing item: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
