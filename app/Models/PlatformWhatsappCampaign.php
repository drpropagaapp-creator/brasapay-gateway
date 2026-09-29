<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformWhatsappCampaign extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'created_by',
        'status',
        'message',
        'delay_seconds',
        'filters',
        'queued_count',
        'skipped_count',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(PlatformWhatsappDispatch::class, 'campaign_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'queued_count' => (int) $this->queued_count,
            'skipped_count' => (int) $this->skipped_count,
            'delay_seconds' => (int) $this->delay_seconds,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
