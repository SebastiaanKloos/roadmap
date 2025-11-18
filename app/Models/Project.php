<?php

namespace App\Models;

use App\Traits\Sluggable;
use App\Traits\HasOgImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Project extends Model
{
    use HasFactory, Sluggable, HasOgImage;

    public $fillable = [
        'title',
        'slug',
        'group',
        'icon',
        'url',
        'description',
        'repo',
        'private',
        'collapsible',
        'anonymous_items',
        'sort_order',
    ];

    protected $casts = [
        'private' => 'boolean',
        'collapsible' => 'boolean',
        'anonymous_items' => 'boolean',
    ];

    public function boards()
    {
        return $this->hasMany(Board::class)->orderBy('sort_order');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_member')->using(ProjectMember::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function scopeVisibleForCurrentUser($query)
    {
        if (auth()->user()?->hasAdminAccess()) {
            return $query;
        }

        if (auth()->check()) {
            return $query
                ->whereHas('members', fn (Builder $query) => $query->where('user_id', auth()->id()))
                ->orWhere('private', false);
        }

        return $query->where('private', false);
    }

    public function shouldAnonymizeForUser(?User $user = null): bool
    {
        if (!$this->anonymous_items) {
            return false;
        }

        $user = $user ?? auth()->user();

        if (!$user) {
            return true;
        }

        if ($user->hasAdminAccess()) {
            return false;
        }

        if ($this->members()->where('user_id', $user->id)->exists()) {
            return false;
        }

        return true;
    }
}
