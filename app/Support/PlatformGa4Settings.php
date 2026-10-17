<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Propriedade GA4 da plataforma (painel do operador).
 * O GA4 de cada produto continua em conversion_pixels.
 */
final class PlatformGa4Settings
{
    public const KEY_ENABLED = 'platform_ga4_enabled';

    public const KEY_MEASUREMENT_ID = 'platform_ga4_measurement_id';

    public const KEY_API_SECRET = 'platform_ga4_api_secret';

    /** @var list<string> */
    public const FORM_KEYS = [
        self::KEY_ENABLED,
        self::KEY_MEASUREMENT_ID,
        self::KEY_API_SECRET,
        'platform_ga4_api_secret_configured',
    ];

    public static function isEnabled(): bool
    {
        $value = Setting::get(self::KEY_ENABLED, '0', null);

        return $value === '1' || $value === 1 || $value === true || $value === 'true';
    }

    public static function measurementId(): string
    {
        return self::normalizeMeasurementId((string) Setting::get(self::KEY_MEASUREMENT_ID, '', null));
    }

    public static function hasApiSecret(): bool
    {
        return self::apiSecret() !== '';
    }

    public static function apiSecret(): string
    {
        $raw = Setting::get(self::KEY_API_SECRET, '', null);
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }

        try {
            return trim((string) Crypt::decryptString($raw));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function canCollect(): bool
    {
        return self::isEnabled() && self::measurementId() !== '';
    }

    public static function canSendServerPurchase(): bool
    {
        return self::canCollect() && self::hasApiSecret();
    }

    /**
     * @return array{enabled: bool, measurement_id: string, browser_purchase: bool}
     */
    public static function inertiaConfig(): array
    {
        $enabled = self::canCollect();

        return [
            'enabled' => $enabled,
            'measurement_id' => $enabled ? self::measurementId() : '',
            'browser_purchase' => $enabled && ! self::hasApiSecret(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            self::KEY_ENABLED => self::isEnabled() ? '1' : '0',
            self::KEY_MEASUREMENT_ID => self::measurementId(),
            'platform_ga4_api_secret_configured' => self::hasApiSecret(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function persistFromValidated(array $validated): void
    {
        $touches = array_key_exists(self::KEY_ENABLED, $validated)
            || array_key_exists(self::KEY_MEASUREMENT_ID, $validated)
            || array_key_exists(self::KEY_API_SECRET, $validated);

        if (! $touches) {
            return;
        }

        $measurementId = array_key_exists(self::KEY_MEASUREMENT_ID, $validated)
            ? trim((string) ($validated[self::KEY_MEASUREMENT_ID] ?? ''))
            : self::measurementId();

        if ($measurementId !== '' && self::normalizeMeasurementId($measurementId) === '') {
            throw ValidationException::withMessages([
                self::KEY_MEASUREMENT_ID => 'Informe um Measurement ID válido, no formato G-XXXXXXXX.',
            ]);
        }

        $measurementId = self::normalizeMeasurementId($measurementId);

        $enabled = array_key_exists(self::KEY_ENABLED, $validated)
            ? self::isTruthy($validated[self::KEY_ENABLED] ?? false)
            : self::isEnabled();

        if ($enabled && $measurementId === '') {
            throw ValidationException::withMessages([
                self::KEY_MEASUREMENT_ID => 'Informe o Measurement ID para ativar o Google Analytics.',
            ]);
        }

        if (array_key_exists(self::KEY_API_SECRET, $validated)) {
            $secret = trim((string) ($validated[self::KEY_API_SECRET] ?? ''));
            if ($secret !== '') {
                if (! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $secret)) {
                    throw ValidationException::withMessages([
                        self::KEY_API_SECRET => 'O API Secret do Measurement Protocol é inválido.',
                    ]);
                }
                Setting::set(self::KEY_API_SECRET, Crypt::encryptString($secret), null);
            }
        }

        Setting::set(self::KEY_ENABLED, $enabled ? '1' : '0', null);
        Setting::set(self::KEY_MEASUREMENT_ID, $measurementId, null);
    }

    public static function normalizeMeasurementId(?string $raw): string
    {
        $id = strtoupper(trim((string) $raw));
        if ($id === '' || ! preg_match('/^G-[A-Z0-9]+$/', $id)) {
            return '';
        }

        return $id;
    }

    public static function clientIdFromCookie(?string $gaCookie): ?string
    {
        $gaCookie = trim((string) $gaCookie);
        if ($gaCookie === '') {
            return null;
        }

        if (preg_match('/^GA\d+\.\d+\.(\d+\.\d+)$/', $gaCookie, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public static function stampClientId(Order $order): void
    {
        if (! self::canCollect()) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! empty($meta['ga_client_id'])) {
            return;
        }

        $request = request();
        if (! $request instanceof Request) {
            return;
        }

        $clientId = self::clientIdFromCookie($request->cookie('_ga'));
        if ($clientId === null) {
            return;
        }

        $meta['ga_client_id'] = $clientId;
        $order->metadata = $meta;
    }

    private static function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
