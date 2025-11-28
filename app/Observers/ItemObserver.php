<?php

namespace App\Observers;

use Throwable;
use Mail;
use App\Models\Item;
use App\Models\User;
use App\Models\Project;
use App\Enums\ItemActivity;
use App\Settings\GeneralSettings;
use App\Jobs\SendWebhookForNewItemJob;
use App\Jobs\SyncItemToLinearJob;
use Illuminate\Support\Facades\Storage;
use App\Mail\Admin\ItemHasBeenCreatedEmail;
use App\Notifications\Item\ItemUpdatedNotification;

class ItemObserver
{
    public function created(Item $item)
    {
        ItemActivity::createForItem($item, ItemActivity::Created);

        if ($item->isPrivate()) {
            return;
        }

        if ($receivers = app(GeneralSettings::class)->send_notifications_to) {
            foreach ($receivers as $receiver) {
                if (!isset($receiver['type'])) {
                    continue;
                }

                if (
                    isset($receiver['projects']) &&
                    Project::whereIn('id', $receiver['projects'])->count() &&
                    !in_array($item->project_id, $receiver['projects'])
                ) {
                    continue;
                }

                match ($receiver['type']) {
                    'email' => Mail::to($receiver['webhook'])->send(new ItemHasBeenCreatedEmail($receiver, $item)),
                    'discord', 'slack' => dispatch(new SendWebhookForNewItemJob($item, $receiver)),
                };
            }
        }

        // Sync to Linear if enabled and sync direction allows it
        if ($this->shouldSyncToLinear()) {
            dispatch(new SyncItemToLinearJob($item))->afterCommit();
        }
    }

    public function updating(Item $item)
    {
        $isDirty = false;

        if ($item->isDirty('board_id') && $item->board) {
            ItemActivity::createForItem($item, ItemActivity::MovedToBoard, [
                'board' => $item->board->title,
            ]);

            $isDirty = true;
        }

        if ($item->isDirty('project_id') && $item->project) {
            ItemActivity::createForItem($item, ItemActivity::MovedToProject, [
                'project' => $item->project->title,
            ]);

            $isDirty = true;
        }

        if ($item->isDirty('pinned') && $item->pinned) {
            ItemActivity::createForItem($item, ItemActivity::Pinned);

            $isDirty = true;
        }

        if ($item->isDirty('pinned') && !$item->pinned) {
            ItemActivity::createForItem($item, ItemActivity::Unpinned);

            $isDirty = true;
        }

        if ($item->isDirty('private') && $item->private) {
            ItemActivity::createForItem($item, ItemActivity::MadePrivate);

            $isDirty = true;
        }

        if ($item->isDirty('private') && !$item->private) {
            ItemActivity::createForItem($item, ItemActivity::MadePublic);

            $isDirty = true;
        }

        if ($item->isDirty('issue_number') && !$item->issue_number) {
            ItemActivity::createForItem($item, ItemActivity::LinkedToIssue, [
                'issue_number' => $item->issue_number,
                'repo' => $item->project->repo,
            ]);
        }

        if ($isDirty && $item->notify_subscribers) {
            $users = $item->subscribedVotes()->with('user')->get()->pluck('user');

            $users->each(function (User $user) use ($item) {
                $user->notify(new ItemUpdatedNotification($item));
            });
        }

        $item->updateQuietly(['notify_subscribers' => true]);
    }

    public function updated(Item $item)
    {
        // Linear sync is handled in saved() hook below to avoid double dispatching
    }

    public function saved(Item $item)
    {
        // Sync to Linear after model is saved (fires after both create and update)
        // This ensures the database has the latest data when the job runs
        if ($this->shouldSyncToLinear() && $item->isSyncedToLinear() && ! $item->wasRecentlyCreated) {
            if ($item->wasChanged(['title', 'content', 'board_id'])) {
                dispatch(new SyncItemToLinearJob($item))->afterResponse();
            }
        }
    }

    public function deleting(Item $item)
    {
        try {
            Storage::delete('public/og-' . $item->slug . '-' . $item->id . '.jpg');
        } catch (Throwable $exception) {
        }

        $item->votes()->delete();
        $item->parentComments()->delete();
        $item->comments()->delete();
        $item->changelogs()->detach();

        // Archive Linear issue if synced
        if ($item->isSyncedToLinear() && $this->shouldSyncToLinear()) {
            // Archive the Linear issue
            try {
                $linearService = app(\App\Services\LinearService::class);
                $linearService->archiveIssue($item->linear_id);
            } catch (Throwable $e) {
                // Log but don't block deletion
                logger()->error('Failed to archive Linear issue on item deletion', [
                    'item_id' => $item->id,
                    'linear_id' => $item->linear_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check if Linear sync is enabled and direction allows to_linear
     */
    protected function shouldSyncToLinear(): bool
    {
        if (! config('linear.enabled')) {
            return false;
        }

        $direction = config('linear.sync.direction');

        return in_array($direction, ['both', 'to_linear']);
    }
}
