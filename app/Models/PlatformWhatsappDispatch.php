<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformWhatsappDispatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'campaign_id',
        'event_type',
        'phone',
        'message',
        'status',
        'wa_status',
        'provider_message_id',
        'track_id',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PlatformWhatsappCampaign::class, 'campaign_id');
    }

    public static function cancelPendingForPhone(string $phone, string $reason): int
    {
        return static::query()
            ->where('phone', $phone)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status' => self::STATUS_CANCELED,
                'error' => $reason,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'campaign_id' => $this->campaign_id,
            'event_type' => $this->event_type,
            'phone' => $this->phone,
            'status' => $this->status,
            'wa_status' => $this->wa_status,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
