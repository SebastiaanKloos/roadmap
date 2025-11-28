<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinearSyncMapping extends Model
{
    protected $fillable = [
        'item_id',
        'linear_id',
        'linear_identifier',
        'linear_team_key',
        'linear_labels',
        'last_synced_at',
        'sync_status',
        'sync_direction',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'linear_labels' => 'array',
        'metadata' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function markAsSynced(): void
    {
        $this->update([
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsError(string $message): void
    {
        $this->update([
            'sync_status' => 'error',
            'error_message' => $message,
        ]);
    }

    public function scopeSynced($query)
    {
        return $query->where('sync_status', 'synced');
    }

    public function scopePending($query)
    {
        return $query->where('sync_status', 'pending');
    }

    public function scopeError($query)
    {
        return $query->where('sync_status', 'error');
    }

    public function scopeByTeam($query, string $teamKey)
    {
        return $query->where('linear_team_key', $teamKey);
    }
}
