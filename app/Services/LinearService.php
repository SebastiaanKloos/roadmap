<?php

namespace App\Services;

use App\Models\Item;
use App\Models\User;
use App\Models\Project;
use App\Models\LinearSyncLog;
use App\Models\LinearSyncMapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LinearService
{
    protected string $apiUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('linear.api.url');
        $this->apiKey = config('linear.api.key');
    }

    public function isEnabled(): bool
    {
        return config('linear.enabled') && ! empty($this->apiKey);
    }

    /**
     * Execute a GraphQL query against the Linear API
     */
    public function query(string $query, array $variables = []): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $payload = ['query' => $query];

            // Only add variables if they exist, and ensure it's an object
            if (! empty($variables)) {
                $payload['variables'] = (object) $variables;
            }

            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl, $payload);

            if ($response->failed()) {
                Log::error('Linear API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            if (isset($data['errors'])) {
                Log::error('Linear API returned errors', ['errors' => $data['errors'], 'body' => $response->body()]);

                return null;
            }

            return $data['data'] ?? null;
        } catch (Throwable $e) {
            Log::error('Linear API exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Check if a Linear issue should be synced based on label filtering
     */
    public function shouldSyncFromLinear(array $linearIssue): bool
    {
        $requiredLabels = config('linear.filtering.labels');

        // If no labels configured, sync everything
        if (empty($requiredLabels)) {
            return true;
        }

        $issueLabels = collect($linearIssue['labels']['nodes'] ?? [])
            ->pluck('name')
            ->map(fn ($label) => strtolower(trim($label)))
            ->toArray();

        // Check if issue has at least one of the required labels
        return ! empty(array_intersect(
            array_map('strtolower', $requiredLabels),
            $issueLabels
        ));
    }

    /**
     * Get Linear team ID for a roadmap project based on mapping
     */
    public function getLinearTeamForProject(Project $project): ?string
    {
        $mapping = config('linear.mapping.projects');
        $normalizedSlug = $this->normalizeProjectSlug($project->slug);

        // Check if normalized slug exists in mapping
        if (isset($mapping[$normalizedSlug])) {
            return $this->getTeamIdByKey($mapping[$normalizedSlug]);
        }

        // Check if full slug exists in mapping (backward compatibility)
        if (isset($mapping[$project->slug])) {
            return $this->getTeamIdByKey($mapping[$project->slug]);
        }

        // Fall back to default team
        return config('linear.workspace.team_id');
    }

    /**
     * Get roadmap project for a Linear team key
     */
    public function getRoadmapProjectForTeam(string $linearTeamKey): ?Project
    {
        $mapping = config('linear.mapping.projects');

        // Find roadmap slug by Linear team name
        $roadmapSlug = array_search($linearTeamKey, $mapping);

        if ($roadmapSlug) {
            return $this->findProjectBySlug($roadmapSlug);
        }

        // Auto-create project if enabled
        if (config('linear.mapping.auto_create_projects')) {
            return $this->createProjectFromLinearTeam($linearTeamKey);
        }

        return null;
    }

    /**
     * Get Linear team ID by team key/name
     */
    protected function getTeamIdByKey(string $teamKey): ?string
    {
        $query = <<<'GQL'
            query($teamKey: String!) {
                teams(filter: { key: { eq: $teamKey } }) {
                    nodes {
                        id
                        key
                        name
                    }
                }
            }
        GQL;

        $result = $this->query($query, ['teamKey' => $teamKey]);

        return $result['teams']['nodes'][0]['id'] ?? null;
    }

    /**
     * Get Linear workflow state ID by state name
     */
    public function getStateIdByName(string $teamId, string $stateName): ?string
    {
        $query = <<<'GQL'
            query($teamId: String!) {
                team(id: $teamId) {
                    states {
                        nodes {
                            id
                            name
                        }
                    }
                }
            }
        GQL;

        $result = $this->query($query, ['teamId' => $teamId]);

        $states = collect($result['team']['states']['nodes'] ?? []);

        return $states->firstWhere('name', $stateName)['id'] ?? null;
    }

    /**
     * Create a Linear issue from a roadmap item
     */
    public function createIssue(Item $item): ?array
    {
        $teamId = $this->getLinearTeamForProject($item->project);

        if (! $teamId) {
            Log::warning('No Linear team ID found for project', ['project' => $item->project->slug]);

            return null;
        }

        // Get state ID based on board mapping
        $stateId = null;
        $linearStateName = $this->getLinearStateForBoard($item->board);
        if ($linearStateName) {
            $stateId = $this->getStateIdByName($teamId, $linearStateName);
        }

        $query = <<<'GQL'
            mutation($teamId: String!, $title: String!, $description: String, $stateId: String) {
                issueCreate(input: {
                    teamId: $teamId
                    title: $title
                    description: $description
                    stateId: $stateId
                }) {
                    success
                    issue {
                        id
                        identifier
                        title
                        url
                        labels {
                            nodes {
                                id
                                name
                            }
                        }
                        team {
                            key
                            name
                        }
                    }
                }
            }
        GQL;

        $result = $this->query($query, [
            'teamId' => $teamId,
            'title' => $item->title,
            'description' => $item->content,
            'stateId' => $stateId,
        ]);

        if ($result && $result['issueCreate']['success']) {
            LinearSyncLog::logSuccess(
                $item->id,
                $result['issueCreate']['issue']['id'],
                'create',
                'to_linear',
                'Linear issue created successfully',
                $result['issueCreate']['issue']
            );

            return $result['issueCreate']['issue'];
        }

        LinearSyncLog::logError(
            $item->id,
            null,
            'create',
            'to_linear',
            'Failed to create Linear issue'
        );

        return null;
    }

    /**
     * Update a Linear issue
     */
    public function updateIssue(string $linearId, array $data): ?array
    {
        $updateFields = [];
        if (isset($data['title'])) {
            $updateFields[] = 'title: $title';
        }
        if (isset($data['description'])) {
            $updateFields[] = 'description: $description';
        }
        if (isset($data['stateId'])) {
            $updateFields[] = 'stateId: $stateId';
        }

        $fieldsString = implode("\n                    ", $updateFields);

        $query = <<<GQL
            mutation(\$issueId: String!, \$title: String, \$description: String, \$stateId: String) {
                issueUpdate(id: \$issueId, input: {
                    $fieldsString
                }) {
                    success
                    issue {
                        id
                        identifier
                        title
                        url
                    }
                }
            }
        GQL;

        $variables = ['issueId' => $linearId];
        if (isset($data['title'])) {
            $variables['title'] = $data['title'];
        }
        if (isset($data['description'])) {
            $variables['description'] = $data['description'];
        }
        if (isset($data['stateId'])) {
            $variables['stateId'] = $data['stateId'];
        }

        $result = $this->query($query, $variables);

        if ($result && $result['issueUpdate']['success']) {
            return $result['issueUpdate']['issue'];
        }

        return null;
    }

    /**
     * Get a Linear issue by ID
     */
    public function getIssue(string $linearId): ?array
    {
        $query = <<<'GQL'
            query($issueId: String!) {
                issue(id: $issueId) {
                    id
                    identifier
                    title
                    description
                    url
                    state {
                        id
                        name
                    }
                    team {
                        id
                        key
                        name
                    }
                    labels {
                        nodes {
                            id
                            name
                        }
                    }
                }
            }
        GQL;

        $result = $this->query($query, ['issueId' => $linearId]);

        return $result['issue'] ?? null;
    }

    /**
     * Archive/delete a Linear issue
     */
    public function archiveIssue(string $linearId): bool
    {
        $query = <<<'GQL'
            mutation($issueId: String!) {
                issueArchive(id: $issueId) {
                    success
                }
            }
        GQL;

        $result = $this->query($query, ['issueId' => $linearId]);

        return $result['issueArchive']['success'] ?? false;
    }

    /**
     * Sync an item to Linear (create or update)
     */
    public function syncItemToLinear(Item $item, bool $forceRefresh = true): ?LinearSyncMapping
    {
        if (! $this->isEnabled()) {
            return null;
        }

        // Refresh item from database to ensure we have latest data
        if ($forceRefresh) {
            $item->refresh()->load('board', 'project');
        }

        // Check if item already has a Linear mapping
        $mapping = LinearSyncMapping::where('item_id', $item->id)->first();

        if ($mapping) {
            // Update existing Linear issue
            // Always sync current state (title, description, and board state)
            $updateData = [
                'title' => $item->title,
                'description' => $item->content,
            ];

            // Map board to Linear state (always include to ensure state is in sync)
            $linearStateName = $this->getLinearStateForBoard($item->board);
            if ($linearStateName) {
                $teamId = $this->getLinearTeamForProject($item->project);
                $stateId = $this->getStateIdByName($teamId, $linearStateName);
                if ($stateId) {
                    $updateData['stateId'] = $stateId;
                }
            }

            $updated = $this->updateIssue($mapping->linear_id, $updateData);

            if ($updated) {
                $mapping->markAsSynced();

                LinearSyncLog::logSuccess(
                    $item->id,
                    $mapping->linear_id,
                    'update',
                    'to_linear',
                    'Linear issue synced: ' . $item->board->title
                );

                return $mapping;
            }

            $mapping->markAsError('Failed to update Linear issue');

            return null;
        }

        // Create new Linear issue
        $issue = $this->createIssue($item);

        if (! $issue) {
            return null;
        }

        // Create mapping
        $mapping = LinearSyncMapping::create([
            'item_id' => $item->id,
            'linear_id' => $issue['id'],
            'linear_identifier' => $issue['identifier'],
            'linear_team_key' => $issue['team']['key'] ?? null,
            'linear_labels' => collect($issue['labels']['nodes'] ?? [])->pluck('name')->toArray(),
            'sync_status' => 'synced',
            'sync_direction' => 'to_linear',
            'last_synced_at' => now(),
        ]);

        // Update item with Linear data
        $item->update([
            'linear_id' => $issue['id'],
            'linear_url' => $issue['url'],
        ]);

        return $mapping;
    }

    /**
     * Sync from Linear to roadmap item
     */
    public function syncItemFromLinear(string $linearId): ?Item
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $issue = $this->getIssue($linearId);

        if (! $issue) {
            Log::warning('Linear issue not found', ['linear_id' => $linearId]);

            return null;
        }

        // Check if issue should be synced based on labels
        if (! $this->shouldSyncFromLinear($issue)) {
            LinearSyncLog::logSkipped(
                null,
                $linearId,
                'sync',
                'Linear issue does not have required labels',
                ['labels' => $issue['labels']['nodes'] ?? []]
            );

            return null;
        }

        // Check if mapping already exists
        $mapping = LinearSyncMapping::where('linear_id', $linearId)->first();

        if ($mapping) {
            // Update existing item
            $item = $mapping->item;

            // Map Linear state to board
            $board = $this->mapLinearStateToBoard($issue['state']['name'] ?? null, $item->project);

            $item->update([
                'title' => $issue['title'],
                'content' => $issue['description'] ?? '',
                'board_id' => $board?->id ?? $item->board_id,
                'linear_url' => $issue['url'],
            ]);

            $mapping->update([
                'linear_identifier' => $issue['identifier'],
                'linear_team_key' => $issue['team']['key'] ?? null,
                'linear_labels' => collect($issue['labels']['nodes'] ?? [])->pluck('name')->toArray(),
            ]);
            $mapping->markAsSynced();

            LinearSyncLog::logSuccess(
                $item->id,
                $linearId,
                'update',
                'from_linear',
                'Roadmap item updated from Linear'
            );

            return $item;
        }

        // Create new item
        $project = $this->getRoadmapProjectForTeam($issue['team']['key']);

        if (! $project) {
            LinearSyncLog::logError(
                null,
                $linearId,
                'create',
                'from_linear',
                'No roadmap project found for Linear team',
                null,
                ['team_key' => $issue['team']['key']]
            );

            return null;
        }

        $board = $this->mapLinearStateToBoard($issue['state']['name'] ?? null, $project);

        if (! $board) {
            LinearSyncLog::logError(
                null,
                $linearId,
                'create',
                'from_linear',
                'No board found for Linear state',
                null,
                ['state' => $issue['state']['name'] ?? null]
            );

            return null;
        }

        $item = Item::create([
            'title' => $issue['title'],
            'content' => $issue['description'] ?? '',
            'project_id' => $project->id,
            'board_id' => $board->id,
            'user_id' => auth()->id() ?? User::where('role', 'admin')->first()?->id,
            'linear_id' => $linearId,
            'linear_url' => $issue['url'],
        ]);

        LinearSyncMapping::create([
            'item_id' => $item->id,
            'linear_id' => $linearId,
            'linear_identifier' => $issue['identifier'],
            'linear_team_key' => $issue['team']['key'] ?? null,
            'linear_labels' => collect($issue['labels']['nodes'] ?? [])->pluck('name')->toArray(),
            'sync_status' => 'synced',
            'sync_direction' => 'from_linear',
            'last_synced_at' => now(),
        ]);

        LinearSyncLog::logSuccess(
            $item->id,
            $linearId,
            'create',
            'from_linear',
            'Roadmap item created from Linear issue'
        );

        return $item;
    }

    /**
     * Normalize board slug by removing ID prefix if present
     * Converts "1-planned" to "planned" for mapping lookups
     */
    protected function normalizeBoardSlug(string $slug): string
    {
        // Remove ID prefix pattern (e.g., "1-planned" becomes "planned")
        return preg_replace('/^\d+-/', '', $slug);
    }

    /**
     * Normalize project slug by removing version or ID prefix if present
     * Converts "v2-ploi" to "ploi" or "14-core" to "core" for mapping lookups
     */
    protected function normalizeProjectSlug(string $slug): string
    {
        // Remove version prefix pattern (e.g., "v2-ploi" becomes "ploi")
        // OR remove ID prefix pattern (e.g., "14-core" becomes "core")
        return preg_replace('/^(v\d+|\d+)-/', '', $slug);
    }

    /**
     * Find project by slug, supporting both full slug and normalized slug
     */
    protected function findProjectBySlug(string $searchSlug): ?Project
    {
        // Try exact match first
        $project = Project::where('slug', $searchSlug)->first();

        if ($project) {
            return $project;
        }

        // Try normalized match (match projects where normalized slug matches)
        return Project::all()->first(function ($project) use ($searchSlug) {
            return $this->normalizeProjectSlug($project->slug) === $this->normalizeProjectSlug($searchSlug);
        });
    }

    /**
     * Find board by slug, supporting both full slug and normalized slug
     */
    protected function findBoardBySlug(Project $project, string $searchSlug): ?\App\Models\Board
    {
        // Try exact match first
        $board = $project->boards()->where('slug', $searchSlug)->first();

        if ($board) {
            return $board;
        }

        // Try normalized match (match boards where normalized slug matches)
        return $project->boards()->get()->first(function ($board) use ($searchSlug) {
            return $this->normalizeBoardSlug($board->slug) === $this->normalizeBoardSlug($searchSlug);
        });
    }

    /**
     * Get Linear state name for a board, using normalized slug for mapping
     */
    protected function getLinearStateForBoard(\App\Models\Board $board): ?string
    {
        $boardMapping = config('linear.mapping.boards');
        $normalizedSlug = $this->normalizeBoardSlug($board->slug);

        // Check if normalized slug exists in mapping
        if (isset($boardMapping[$normalizedSlug])) {
            return $boardMapping[$normalizedSlug];
        }

        // Check if full slug exists in mapping (backward compatibility)
        if (isset($boardMapping[$board->slug])) {
            return $boardMapping[$board->slug];
        }

        return null;
    }

    /**
     * Map Linear state name to roadmap board
     */
    protected function mapLinearStateToBoard(? string $stateName, Project $project)
    {
        if (! $stateName) {
            $defaultSlug = config('linear.mapping.default_board');
            return $this->findBoardBySlug($project, $defaultSlug);
        }

        $boardMapping = config('linear.mapping.boards');

        // Reverse lookup: find board slug by Linear state name
        $boardSlug = array_search($stateName, $boardMapping);

        if ($boardSlug) {
            return $this->findBoardBySlug($project, $boardSlug);
        }

        // Fall back to default board
        $defaultSlug = config('linear.mapping.default_board');
        return $this->findBoardBySlug($project, $defaultSlug);
    }

    /**
     * Auto-create a roadmap project from a Linear team
     */
    protected function createProjectFromLinearTeam(string $linearTeamKey): ?Project
    {
        // This is a placeholder - you may want to customize this
        return Project::create([
            'title' => $linearTeamKey,
            'slug' => str($linearTeamKey)->slug(),
            'description' => "Auto-created from Linear team: {$linearTeamKey}",
            'private' => false,
        ]);
    }
}
