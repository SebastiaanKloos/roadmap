<?php

namespace App\Console\Commands\Linear;

use App\Models\Item;
use App\Jobs\SyncItemToLinearJob;
use Illuminate\Console\Command;

class SyncAllCommand extends Command
{
    protected $signature = 'linear:sync-all
                            {--project= : Only sync items from specific project slug}
                            {--limit= : Limit number of items to sync}';

    protected $description = 'Sync all roadmap items to Linear';

    public function handle(): int
    {
        $this->info('Starting bulk sync of roadmap items to Linear...');
        $this->newLine();

        $query = Item::query();

        // Filter by project if specified
        if ($projectSlug = $this->option('project')) {
            $query->whereHas('project', fn ($q) => $q->where('slug', $projectSlug));
            $this->line("Filtering by project: {$projectSlug}");
        }

        // Apply limit if specified
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
            $this->line("Limiting to {$limit} items");
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            $this->warn('No items found to sync.');

            return self::SUCCESS;
        }

        $this->info("Found {$items->count()} items to sync");
        $this->newLine();

        $bar = $this->output->createProgressBar($items->count());
        $bar->start();

        foreach ($items as $item) {
            dispatch(new SyncItemToLinearJob($item));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('✓ All sync jobs have been dispatched to the queue');
        $this->line('  Monitor the queue worker to see progress');

        return self::SUCCESS;
    }
}
