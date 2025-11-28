<?php

namespace App\Console\Commands\Linear;

use App\Services\LinearService;
use Illuminate\Console\Command;

class ListTeamsCommand extends Command
{
    protected $signature = 'linear:list-teams
                            {--json : Output as JSON}
                            {--detailed : Show detailed information including states and members}';

    protected $description = 'List all Linear teams with their IDs, keys, and information';

    public function handle(LinearService $linearService): int
    {
        $this->info('Fetching Linear teams...');
        $this->newLine();

        if (! $linearService->isEnabled()) {
            $this->error('Linear integration is disabled or API key is not configured.');
            $this->info('Please set LINEAR_ENABLED=true and LINEAR_API_KEY in your .env file.');

            return self::FAILURE;
        }

        $detailed = $this->option('detailed');

        // GraphQL query to fetch teams
        $query = $detailed ? $this->getDetailedQuery() : $this->getBasicQuery();

        try {
            $result = $linearService->query($query);

            if (! $result || ! isset($result['teams']['nodes'])) {
                $this->error('Failed to fetch teams from Linear API');

                return self::FAILURE;
            }

            $teams = $result['teams']['nodes'];

            if (empty($teams)) {
                $this->warn('No teams found in your Linear workspace.');

                return self::SUCCESS;
            }

            // Output as JSON if requested
            if ($this->option('json')) {
                $this->line(json_encode($teams, JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            // Display teams
            $this->displayTeams($teams, $detailed);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error fetching teams: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function getBasicQuery(): string
    {
        return <<<'GQL'
            query {
                teams {
                    nodes {
                        id
                        key
                        name
                        description
                        private
                    }
                }
            }
        GQL;
    }

    protected function getDetailedQuery(): string
    {
        return <<<'GQL'
            query {
                teams {
                    nodes {
                        id
                        key
                        name
                        description
                        private
                        states {
                            nodes {
                                id
                                name
                                type
                            }
                        }
                        members {
                            nodes {
                                user {
                                    name
                                    email
                                }
                            }
                        }
                    }
                }
            }
        GQL;
    }

    protected function displayTeams(array $teams, bool $detailed): void
    {
        $this->info('Found '.count($teams).' team(s):');
        $this->newLine();

        foreach ($teams as $index => $team) {
            // Team header
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->line('<fg=cyan;options=bold>Team '.($index + 1).': '.$team['name'].'</>');
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->newLine();

            // Basic info table
            $this->table(
                ['Property', 'Value'],
                [
                    ['ID', '<fg=green>'.$team['id'].'</>'],
                    ['Key', '<fg=yellow>'.$team['key'].'</>'],
                    ['Name', $team['name']],
                    ['Description', $team['description'] ?? 'N/A'],
                    ['Private', $team['private'] ? 'Yes' : 'No'],
                ]
            );

            // Configuration example
            $this->newLine();
            $this->line('<fg=magenta>💡 Configuration Example:</>');
            $this->line('  <fg=gray># Use this team as default:</>');
            $this->line('  LINEAR_TEAM_ID='.$team['id']);
            $this->newLine();
            $this->line('  <fg=gray># Or map to a roadmap project:</>');
            $this->line('  LINEAR_PROJECT_MAPPING=your-project-slug:'.$team['key']);

            // Detailed information
            if ($detailed) {
                $this->newLine();

                // States
                if (isset($team['states']['nodes']) && ! empty($team['states']['nodes'])) {
                    $this->line('<fg=blue;options=bold>Workflow States:</>');
                    $statesData = [];
                    foreach ($team['states']['nodes'] as $state) {
                        $statesData[] = [
                            $state['name'],
                            ucfirst($state['type']),
                            substr($state['id'], 0, 20).'...',
                        ];
                    }
                    $this->table(['State Name', 'Type', 'ID'], $statesData);
                    $this->newLine();
                }

                // Members
                if (isset($team['members']['nodes']) && ! empty($team['members']['nodes'])) {
                    $this->line('<fg=blue;options=bold>Team Members ('.count($team['members']['nodes']).'):</>');
                    $membersData = [];
                    foreach ($team['members']['nodes'] as $member) {
                        $membersData[] = [
                            $member['user']['name'],
                            $member['user']['email'],
                        ];
                    }
                    $this->table(['Name', 'Email'], $membersData);
                }
            }

            $this->newLine();
        }

        // Summary
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('✓ Total teams: '.count($teams));

        // Tips
        if (! $detailed) {
            $this->newLine();
            $this->line('<fg=gray>💡 Tip: Use --detailed flag to see workflow states and team members</>');
        }
    }
}
