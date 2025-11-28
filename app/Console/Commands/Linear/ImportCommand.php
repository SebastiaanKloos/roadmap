<?php

namespace App\Console\Commands\Linear;

use App\Services\LinearService;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'linear:import {linear-id : The Linear issue ID to import}';

    protected $description = 'Import a Linear issue into the roadmap';

    public function handle(LinearService $linearService): int
    {
        $linearId = $this->argument('linear-id');

        $this->info("Importing Linear issue: {$linearId}");

        try {
            $item = $linearService->syncItemFromLinear($linearId);

            if ($item) {
                $this->info('✓ Linear issue imported successfully');
                $this->line("  Item ID: {$item->id}");
                $this->line("  Title: {$item->title}");
                $this->line("  Project: {$item->project->title}");
                $this->line("  Board: {$item->board->title}");
                $this->line("  URL: {$item->view_url}");

                return self::SUCCESS;
            }

            $this->error('✗ Failed to import Linear issue');
            $this->line('  Check logs for details');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('✗ Error importing issue: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
