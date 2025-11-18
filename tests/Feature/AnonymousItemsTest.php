<?php

use App\Models\User;
use App\Models\Project;
use App\Models\Board;
use App\Models\Item;
use App\Models\Comment;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    // Create users with different roles
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->regularUser = User::factory()->create(['role' => 'user']);
    $this->anotherUser = User::factory()->create(['role' => 'user']);

    // Create a project with anonymous_items enabled
    $this->anonymousProject = Project::factory()->create([
        'anonymous_items' => true,
        'private' => false,
    ]);

    // Create a project with anonymous_items disabled
    $this->normalProject = Project::factory()->create([
        'anonymous_items' => false,
        'private' => false,
    ]);

    // Create boards
    $this->anonymousBoard = Board::factory()->create([
        'project_id' => $this->anonymousProject->id,
    ]);

    $this->normalBoard = Board::factory()->create([
        'project_id' => $this->normalProject->id,
    ]);

    // Create items
    $this->anonymousItem = Item::factory()->create([
        'project_id' => $this->anonymousProject->id,
        'board_id' => $this->anonymousBoard->id,
        'user_id' => $this->regularUser->id,
    ]);

    $this->normalItem = Item::factory()->create([
        'project_id' => $this->normalProject->id,
        'board_id' => $this->normalBoard->id,
        'user_id' => $this->regularUser->id,
    ]);

    // Create comments
    $this->anonymousComment = Comment::factory()->create([
        'item_id' => $this->anonymousItem->id,
        'user_id' => $this->regularUser->id,
    ]);

    $this->normalComment = Comment::factory()->create([
        'item_id' => $this->normalItem->id,
        'user_id' => $this->regularUser->id,
    ]);
});

test('project shouldAnonymizeForUser returns true for non-authenticated users on anonymous project', function () {
    expect($this->anonymousProject->shouldAnonymizeForUser(null))->toBeTrue();
});

test('project shouldAnonymizeForUser returns false for non-authenticated users on normal project', function () {
    expect($this->normalProject->shouldAnonymizeForUser(null))->toBeFalse();
});

test('project shouldAnonymizeForUser returns false for admin users on anonymous project', function () {
    expect($this->anonymousProject->shouldAnonymizeForUser($this->admin))->toBeFalse();
});

test('project shouldAnonymizeForUser returns true for regular users on anonymous project', function () {
    expect($this->anonymousProject->shouldAnonymizeForUser($this->regularUser))->toBeTrue();
});

test('project shouldAnonymizeForUser returns false for project members on anonymous project', function () {
    $this->anonymousProject->members()->attach($this->regularUser);

    expect($this->anonymousProject->shouldAnonymizeForUser($this->regularUser))->toBeFalse();
});

test('item shouldShowAnonymous returns true for guests on anonymous project', function () {
    expect($this->anonymousItem->shouldShowAnonymous(null))->toBeTrue();
});

test('item shouldShowAnonymous returns false for admin on anonymous project', function () {
    expect($this->anonymousItem->shouldShowAnonymous($this->admin))->toBeFalse();
});

test('item shouldShowAnonymous returns true for regular users on anonymous project', function () {
    expect($this->anonymousItem->shouldShowAnonymous($this->anotherUser))->toBeTrue();
});

test('item shouldShowAnonymous returns false for project members on anonymous project', function () {
    $this->anonymousProject->members()->attach($this->anotherUser);

    expect($this->anonymousItem->shouldShowAnonymous($this->anotherUser))->toBeFalse();
});

test('item shouldShowAnonymous returns false on normal project', function () {
    expect($this->normalItem->shouldShowAnonymous(null))->toBeFalse();
    expect($this->normalItem->shouldShowAnonymous($this->regularUser))->toBeFalse();
});

test('comment shouldShowAnonymous returns true for guests on anonymous project', function () {
    expect($this->anonymousComment->shouldShowAnonymous(null))->toBeTrue();
});

test('comment shouldShowAnonymous returns false for admin on anonymous project', function () {
    expect($this->anonymousComment->shouldShowAnonymous($this->admin))->toBeFalse();
});

test('comment shouldShowAnonymous returns true for regular users on anonymous project', function () {
    expect($this->anonymousComment->shouldShowAnonymous($this->anotherUser))->toBeTrue();
});

test('comment shouldShowAnonymous returns false for project members on anonymous project', function () {
    $this->anonymousProject->members()->attach($this->anotherUser);

    expect($this->anonymousComment->shouldShowAnonymous($this->anotherUser))->toBeFalse();
});

test('comment shouldShowAnonymous returns false on normal project', function () {
    expect($this->normalComment->shouldShowAnonymous(null))->toBeFalse();
    expect($this->normalComment->shouldShowAnonymous($this->regularUser))->toBeFalse();
});

test('item page shows anonymous user for guests on anonymous project', function () {
    $response = get(route('projects.items.show', [$this->anonymousProject, $this->anonymousItem]));

    $response->assertStatus(200);
    $response->assertSee(trans('general.anonymous-user'));
    // Don't check for absence of real name as it might appear in hidden HTML attributes
});

test('item page shows real user for admin on anonymous project', function () {
    actingAs($this->admin);

    $response = get(route('projects.items.show', [$this->anonymousProject, $this->anonymousItem]));

    $response->assertStatus(200);
    $response->assertSee($this->regularUser->name);
    $response->assertDontSee(trans('general.anonymous-user'));
});

test('item page shows real user for project members on anonymous project', function () {
    $this->anonymousProject->members()->attach($this->anotherUser);
    actingAs($this->anotherUser);

    $response = get(route('projects.items.show', [$this->anonymousProject, $this->anonymousItem]));

    $response->assertStatus(200);
    $response->assertSee($this->regularUser->name);
});

test('item page shows anonymous user for regular users on anonymous project', function () {
    actingAs($this->anotherUser);

    $response = get(route('projects.items.show', [$this->anonymousProject, $this->anonymousItem]));

    $response->assertStatus(200);
    $response->assertSee(trans('general.anonymous-user'));
    // Don't check for absence of real name as it might appear in hidden HTML attributes
});

test('item page shows real user on normal project', function () {
    $response = get(route('projects.items.show', [$this->normalProject, $this->normalItem]));

    $response->assertStatus(200);
    $response->assertSee($this->regularUser->name);
    $response->assertDontSee(trans('general.anonymous-user'));
});

test('comments show anonymous user for guests on anonymous project', function () {
    $response = get(route('projects.items.show', [$this->anonymousProject, $this->anonymousItem]));

    $response->assertStatus(200);
    $response->assertSee(trans('general.anonymous-user'));
});

test('comments show real user for admin on anonymous project', function () {
    actingAs($this->admin);

    $response = get(route('projects.items.show', [$this->anonymousProject, $this->anonymousItem]));

    $response->assertStatus(200);
    $response->assertSee($this->regularUser->name);
});

test('comments show anonymous user for regular users on anonymous project', function () {
    actingAs($this->anotherUser);

    $response = get(route('projects.items.show', [$this->anonymousProject, $this->anonymousItem]));

    $response->assertStatus(200);
    $response->assertSee(trans('general.anonymous-user'));
});

test('item without project does not show anonymous', function () {
    $itemWithoutProject = Item::factory()->create([
        'project_id' => null,
        'board_id' => null,
        'user_id' => $this->regularUser->id,
    ]);

    expect($itemWithoutProject->shouldShowAnonymous(null))->toBeFalse();
});

test('comment on item without project does not show anonymous', function () {
    $itemWithoutProject = Item::factory()->create([
        'project_id' => null,
        'board_id' => null,
        'user_id' => $this->regularUser->id,
    ]);

    $comment = Comment::factory()->create([
        'item_id' => $itemWithoutProject->id,
        'user_id' => $this->regularUser->id,
    ]);

    expect($comment->shouldShowAnonymous(null))->toBeFalse();
});
