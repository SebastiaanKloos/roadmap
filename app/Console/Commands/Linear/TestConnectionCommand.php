<?php

namespace App\Console\Commands\Linear;

use App\Services\LinearService;
use Illuminate\Console\Command;

class TestConnectionCommand extends Command
{
    protected $signature = 'linear:test-connection';

    protected $description = 'Test Linear API connectivity and configuration';

    public function handle(LinearService $linearService): int
    {
        $this->info('Testing Linear API connection...');
        $this->newLine();

        // Check if enabled
        if (! $linearService->isEnabled()) {
            $this->error('Linear integration is disabled or API key is not configured.');
            $this->info('Please set LINEAR_ENABLED=true and LINEAR_API_KEY in your .env file.');

            return self::FAILURE;
        }

        $this->line('✓ Linear integration is enabled');
        $this->line('✓ API key is configured');
        $this->line('✓ API URL: '.config('linear.api.url'));
        $this->newLine();

        // Test API connection with a simple query
        $this->info('Testing API connection...');

        $query = <<<'GQL'
            query {
                viewer {
                    id
                    name
                    email
                }
            }
        GQL;

        try {
            $result = $linearService->query($query);

            if ($result && isset($result['viewer'])) {
                $this->line('✓ API connection successful');
                $this->line('  User: '.$result['viewer']['name']);
                $this->line('  Email: '.$result['viewer']['email']);
                $this->newLine();
            } else {
                $this->error('✗ API connection failed: Invalid response');

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('✗ API connection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // Display configuration
        $this->info('Configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Sync Direction', config('linear.sync.direction')],
                ['Auto Sync', config('linear.sync.auto') ? 'Enabled' : 'Disabled'],
                ['Sync Comments', config('linear.sync.comments') ? 'Yes' : 'No'],
                ['Sync Votes', config('linear.sync.votes') ? 'Yes' : 'No'],
                ['Required Labels', ! empty(config('linear.filtering.labels')) ? implode(', ', config('linear.filtering.labels')) : 'None (sync all)'],
                ['Project Mappings', count(config('linear.mapping.projects')).' configured'],
                ['Board Mappings', count(config('linear.mapping.boards')).' configured'],
            ]
        );

        $this->newLine();
        $this->info('Linear API connection test completed successfully!');

        return self::SUCCESS;
    }
}
