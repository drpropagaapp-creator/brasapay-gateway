<?php

namespace App\Services\Payout;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Services\CajuPay\CajuPayAccountResolver;

/**
 * Provedor de payout da plataforma (saque automático PIX).
 *
 * Gateways com API de cashout: CajuPay, Woovi, BSPay, Versell, Xflow, Okto e OnlyUp (plugin).
 * Preferência configurável em {@see Setting} `platform_payout_gateway`
 * (`auto`, `cajupay`, `woovi`, `bspay`, `versell`, `xflow`, `okto`, `onlyup`).
 * Em `auto`, a ordem fixa é CajuPay → Woovi → BSPay → Versell → Xflow → Okto → OnlyUp — o primeiro globalmente conectado vence.
 * Spacepag permanece no código de payout legado, mas está fora da UI e da ordem automática.
 */
class PlatformPayoutGateway
{
    /** @var list<string> */
    public const PAYOUT_ORDER = ['cajupay', /* 'spacepag', */ 'woovi', 'bspay', 'versell', 'xflow', 'okto', 'onlyup'];

    /**
     * Preferência salva no painel: automático ou forçar um dos gateways.
     *
     * @return 'auto'|'cajupay'|'woovi'|'bspay'|'versell'|'xflow'|'okto'|'onlyup'
     */
    public static function preference(): string
    {
        $v = Setting::get('platform_payout_gateway', null, null);
        if (in_array($v, ['cajupay', /* 'spacepag', */ 'woovi', 'bspay', 'versell', 'xflow', 'okto', 'onlyup'], true)) {
            return $v;
        }

        return 'auto';
    }

    public static function activeSlug(): ?string
    {
        $connected = self::connectedMap();
        if ($connected === []) {
            return null;
        }

        $pref = self::preference();

        if ($pref !== 'auto' && isset($connected[$pref])) {
            return $pref;
        }

        foreach (self::PAYOUT_ORDER as $slug) {
            if (isset($connected[$slug])) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function connectedSlugs(): array
    {
        return array_keys(self::connectedMap());
    }

    public static function isConnected(string $slug): bool
    {
        return isset(self::connectedMap()[$slug]);
    }

    /**
     * Adquirentes com cash-out conectado na plataforma (para o admin escolher quem paga o saque).
     *
     * @return list<array{slug: string, name: string}>
     */
    public static function connectedOptions(): array
    {
        $options = [];
        foreach (self::connectedSlugs() as $slug) {
            $def = GatewayRegistry::get($slug);
            $name = is_string($def['name'] ?? null) ? trim((string) $def['name']) : '';
            $options[] = [
                'slug' => $slug,
                'name' => $name !== '' ? $name : $slug,
            ];
        }

        return $options;
    }

    public static function isEnabled(): bool
    {
        return self::activeSlug() !== null;
    }

    /**
     * @return array<string, true>
     */
    private static function connectedMap(): array
    {
        $connected = [];
        foreach (self::PAYOUT_ORDER as $slug) {
            if ($slug === 'cajupay') {
                if (app(CajuPayAccountResolver::class)->anyConnectedForPayout()) {
                    $connected[$slug] = true;
                }
                continue;
            }
            $cred = GatewayCredential::resolveForPayment(null, $slug);
            if ($cred === null || ! $cred->is_connected) {
                continue;
            }
            if ($slug === 'versell') {
                $creds = $cred->getDecryptedCredentials();
                if (! \App\Gateways\Versell\VersellCredentials::isCashOutReady($creds)) {
                    continue;
                }
            }
            $connected[$slug] = true;
        }

        return $connected;
    }
}
