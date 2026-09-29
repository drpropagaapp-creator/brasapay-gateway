<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformWhatsappTemplate extends Model
{
    public const EVENT_SELLER_REGISTERED = 'seller.registered';

    public const EVENT_SELLER_REJECTED = 'seller.rejected';

    public const EVENT_KYC_APPROVED = 'kyc.approved';

    public const EVENT_KYC_REJECTED = 'kyc.rejected';

    public const EVENT_BROADCAST = 'broadcast';

    protected $fillable = [
        'event_key',
        'enabled',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return [
            self::EVENT_SELLER_REGISTERED,
            self::EVENT_SELLER_REJECTED,
            self::EVENT_KYC_APPROVED,
            self::EVENT_KYC_REJECTED,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::EVENT_SELLER_REGISTERED => 'Cadastro recebido',
            self::EVENT_SELLER_REJECTED => 'Cadastro recusado',
            self::EVENT_KYC_APPROVED => 'KYC aprovado',
            self::EVENT_KYC_REJECTED => 'KYC recusado',
        ];
    }

    public static function ensureDefaults(): void
    {
        $defaults = config('platform_whatsapp.defaults.templates', []);
        if (! is_array($defaults)) {
            return;
        }

        foreach (self::eventKeys() as $key) {
            $message = (string) ($defaults[$key] ?? '');
            if ($message === '') {
                continue;
            }
            static::query()->firstOrCreate(
                ['event_key' => $key],
                ['enabled' => true, 'message' => $message]
            );
        }
    }

    public static function findEnabled(string $eventKey): ?self
    {
        $row = static::query()->where('event_key', $eventKey)->first();
        if (! $row || ! $row->enabled) {
            return null;
        }

        $message = trim((string) $row->message);

        return $message !== '' ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toPublicList(): array
    {
        self::ensureDefaults();
        $labels = self::labels();
        $defaults = config('platform_whatsapp.defaults.templates', []);

        return collect(self::eventKeys())->map(function (string $key) use ($labels, $defaults) {
            $row = static::query()->where('event_key', $key)->first();

            return [
                'event_key' => $key,
                'label' => $labels[$key] ?? $key,
                'enabled' => $row ? (bool) $row->enabled : true,
                'message' => $row?->message ?: (string) ($defaults[$key] ?? ''),
            ];
        })->values()->all();
    }
}
