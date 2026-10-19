<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformWhatsappDispatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

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
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
            ->update([
                'status' => self::STATUS_CANCELED,
                'error' => $reason,
            ]);
    }

    /**
     * Blocks a second transactional notify for the same seller + event.
     * One-shot events (cadastro / KYC aprovado) nunca repetem após pending/sent.
     * Eventos repetíveis (rejeição) bloqueiam pending e sent recente (anti double-click).
     */
    public static function blocksDuplicate(int $userId, string $eventType, int $recentMinutes = 5): bool
    {
        $oneShot = in_array($eventType, [
            PlatformWhatsappTemplate::EVENT_SELLER_REGISTERED,
            PlatformWhatsappTemplate::EVENT_KYC_APPROVED,
        ], true);

        $query = static::query()
            ->where('user_id', $userId)
            ->where('event_type', $eventType);

        if ($oneShot) {
            return $query
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_PROCESSING])
                ->exists();
        }

        return $query
            ->where(function ($inner) use ($recentMinutes) {
                $inner->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
                    ->orWhere(function ($sent) use ($recentMinutes) {
                        $sent->where('status', self::STATUS_SENT)
                            ->where('created_at', '>=', now()->subMinutes(max(1, $recentMinutes)));
                    });
            })
            ->exists();
    }

    public function claimForSending(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $affected = static::query()
            ->where('id', $this->id)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status' => self::STATUS_PROCESSING,
                'updated_at' => now(),
            ]);

        if ($affected === 1) {
            $this->status = self::STATUS_PROCESSING;
        }

        return $affected === 1;
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
