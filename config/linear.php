<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Linear Integration Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable the Linear integration. When disabled, no sync
    | operations will be performed.
    |
    */

    'enabled' => env('LINEAR_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Linear API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Linear API endpoint and authentication. The API URL
    | can be customized to support other tools that use the same API format.
    |
    */

    'api' => [
        'key' => env('LINEAR_API_KEY'),
        'url' => env('LINEAR_API_URL', 'https://api.linear.app/graphql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Linear Workspace Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Linear workspace and default team ID.
    |
    */

    'workspace' => [
        'id' => env('LINEAR_WORKSPACE_ID'),
        'team_id' => env('LINEAR_TEAM_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how synchronization should work:
    | - direction: 'both', 'to_linear', or 'from_linear'
    | - auto: Enable automatic real-time sync via webhooks
    | - comments: Sync comments bi-directionally
    | - votes: Sync votes as Linear priority or reactions
    |
    */

    'sync' => [
        'direction' => env('LINEAR_SYNC_DIRECTION', 'both'),
        'auto' => env('LINEAR_AUTO_SYNC', false),
        'comments' => env('LINEAR_SYNC_COMMENTS', true),
        'votes' => env('LINEAR_SYNC_VOTES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure webhook secret for signature verification.
    |
    */

    'webhook' => [
        'secret' => env('LINEAR_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Label Filtering
    |--------------------------------------------------------------------------
    |
    | Filter which Linear issues should be synced based on labels.
    | Leave empty to sync all issues. Comma-separated list.
    | Example: "roadmap,public,feature-request"
    |
    */

    'filtering' => [
        'labels' => array_values(array_filter(
            array_map('trim', explode(',', env('LINEAR_SYNC_LABELS', '')))
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Project and Board Mapping
    |--------------------------------------------------------------------------
    |
    | Map roadmap projects to Linear teams and roadmap boards to Linear states.
    | Format: "roadmap-slug:linear-team,another-slug:another-team"
    |
    */

    'mapping' => [
        // Map roadmap project slugs to Linear team names
        'projects' => collect(explode(',', env('LINEAR_PROJECT_MAPPING', '')))
            ->filter()
            ->mapWithKeys(function ($mapping) {
                if (! str_contains($mapping, ':')) {
                    return [];
                }
                [$roadmapSlug, $linearTeam] = array_map('trim', explode(':', $mapping, 2));

                return [$roadmapSlug => $linearTeam];
            })
            ->toArray(),

        // Map roadmap board slugs to Linear state names
        'boards' => collect(explode(',', env('LINEAR_BOARD_MAPPING', '')))
            ->filter()
            ->mapWithKeys(function ($mapping) {
                if (! str_contains($mapping, ':')) {
                    return [];
                }
                [$boardSlug, $linearState] = array_map('trim', explode(':', $mapping, 2));

                return [$boardSlug => $linearState];
            })
            ->toArray(),

        // Default board slug for items synced from Linear
        'default_board' => env('LINEAR_DEFAULT_BOARD', 'planned'),

        // Automatically create roadmap projects for unmapped Linear teams
        'auto_create_projects' => env('LINEAR_AUTO_CREATE_PROJECTS', false),
    ],
];