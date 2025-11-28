<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinearSyncLog extends Model
{
    protected $fillable = [
        'item_id',
        'linear_id',
        'action',
        'direction',
        'status',
        'message',
        'payload',
        'error_details',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public static function logSuccess(
        ?int $itemId,
        ?string $linearId,
        string $action,
        string $direction,
        string $message,
        ?array $payload = null
    ): self {
        return static::create([
            'item_id' => $itemId,
            'linear_id' => $linearId,
            'action' => $action,
            'direction' => $direction,
            'status' => 'success',
            'message' => $message,
            'payload' => $payload,
        ]);
    }

    public static function logError(
        ?int $itemId,
        ?string $linearId,
        string $action,
        string $direction,
        string $message,
        ?string $errorDetails = null,
        ?array $payload = null
    ): self {
        return static::create([
            'item_id' => $itemId,
            'linear_id' => $linearId,
            'action' => $action,
            'direction' => $direction,
            'status' => 'failed',
            'message' => $message,
            'error_details' => $errorDetails,
            'payload' => $payload,
        ]);
    }

    public static function logSkipped(
        ?int $itemId,
        ?string $linearId,
        string $action,
        string $message,
        ?array $payload = null
    ): self {
        return static::create([
            'item_id' => $itemId,
            'linear_id' => $linearId,
            'action' => $action,
            'status' => 'skipped',
            'message' => $message,
            'payload' => $payload,
        ]);
    }

    public function scopeSuccess($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeSkipped($query)
    {
        return $query->where('status', 'skipped');
    }

    public function scopeToLinear($query)
    {
        return $query->where('direction', 'to_linear');
    }

    public function scopeFromLinear($query)
    {
        return $query->where('direction', 'from_linear');
    }
}
