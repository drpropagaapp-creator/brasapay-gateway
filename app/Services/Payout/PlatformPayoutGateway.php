<?php

namespace App\Services\Payout;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Services\CajuPay\CajuPayAccountResolver;
use App\Services\PaymentService;

/**
 * Provedor de payout da plataforma (saque automático PIX).
 *
 * Gateways com API de cashout: CajuPay, Woovi, Versell, Xflow, Okto, XPag e OnlyUp (plugin).
 * Preferência configurável em {@see Setting} `platform_payout_gateway`
 * (`auto`, `cajupay`, `woovi`, `versell`, `xflow`, `okto`, `xpag`, `onlyup`).
 * Em `auto`, o saque segue a adquirente do PIX: vence a primeira da ordem do PIX com cash-out conectado.
 * Se nenhuma delas paga saque, cai na ordem fixa CajuPay → Woovi → AssetPay → Versell → Xflow → Okto → XPag → OnlyUp.
 * Spacepag e BSPay permanecem no código de payout legado, mas estão fora da UI e da ordem automática.
 */
class PlatformPayoutGateway
{
    /** @var list<string> */
    public const PAYOUT_ORDER = ['cajupay', /* 'spacepag', */ 'woovi', /* 'bspay', */ 'assetpay', 'versell', 'xflow', 'okto', 'xpag', 'onlyup'];

    /**
     * Preferência salva no painel: automático ou forçar um dos gateways.
     *
     * @return 'auto'|'cajupay'|'woovi'|'assetpay'|'versell'|'xflow'|'okto'|'xpag'|'onlyup'
     */
    public static function preference(): string
    {
        $v = Setting::get('platform_payout_gateway', null, null);
        if (in_array($v, ['cajupay', /* 'spacepag', */ 'woovi', /* 'bspay', */ 'assetpay', 'versell', 'xflow', 'okto', 'xpag', 'onlyup'], true)) {
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

        // Automático: o saque segue a adquirente do PIX. A primeira da ordem do PIX
        // (Financeiro > Adquirentes) que tem cash-out conectado paga o saque — assim,
        // trocar a adquirente do PIX troca também quem paga os saques.
        foreach (self::pixOrder() as $slug) {
            if (isset($connected[$slug])) {
                return $slug;
            }
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
     * Ordem do PIX da plataforma, só com adquirentes que sabem pagar saque.
     *
     * @return list<string>
     */
    public static function pixOrder(): array
    {
        try {
            $pix = app(PaymentService::class)->normalizedGatewayOrder(null)['pix'] ?? [];
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            is_array($pix) ? $pix : [],
            fn ($slug) => is_string($slug) && in_array($slug, self::PAYOUT_ORDER, true)
        ));
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
            if ($slug === 'assetpay') {
                // Saque exige a credencial Cash Out (separada da Cash In que recebe o PIX).
                [$outId, $outSecret] = \App\Gateways\AssetPay\AssetPayDriver::clientCredentials(
                    $cred->getDecryptedCredentials(),
                    \App\Gateways\AssetPay\AssetPayDriver::SCOPE_CASH_OUT
                );
                if ($outId === '' || $outSecret === '') {
                    continue;
                }
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
