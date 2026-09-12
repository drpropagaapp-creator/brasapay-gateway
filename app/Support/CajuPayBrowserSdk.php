<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * URL da API CajuPay usada pelo SDK no navegador (embedded checkout).
 */
final class CajuPayBrowserSdk
{
    public static function directApiBaseUrl(): string
    {
        return rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');
    }

    /**
     * Em HTTPS usa a API direta; em HTTP local (ex.: Laragon) usa proxy same-origin para evitar CORS.
     */
    public static function apiBaseUrlForBrowser(?Request $request = null): string
    {
        $direct = self::directApiBaseUrl();

        if ($request === null) {
            return $direct;
        }

        if ($request->secure()) {
            return $direct;
        }

        $useProxy = filter_var(config('services.cajupay.sdk_browser_proxy', true), FILTER_VALIDATE_BOOLEAN);
        if (! $useProxy) {
            return $direct;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/').'/checkout/cajupay/sdk-api';
    }

    /**
     * Locale BCP-47 esperado pela sessão/SDK CajuPay (`pt-BR`, `en`, `auto`).
     */
    public static function localeFromCheckout(?string $checkoutLocale): string
    {
        $raw = str_replace('_', '-', trim((string) $checkoutLocale));
        if ($raw === '') {
            return 'pt-BR';
        }
        if (strcasecmp($raw, 'auto') === 0) {
            return 'auto';
        }

        return substr($raw, 0, 16);
    }

    public static function partnerCheckoutUrl(?Request $request, ?string $checkoutSlug = null): string
    {
        $referer = trim((string) ($request?->headers->get('referer') ?? ''));
        if ($referer !== '' && filter_var($referer, FILTER_VALIDATE_URL)) {
            return $referer;
        }
        if (is_string($checkoutSlug) && $checkoutSlug !== '') {
            try {
                return url()->route('checkout.show', ['slug' => $checkoutSlug]);
            } catch (\Throwable) {
            }
        }

        return is_string($request?->url()) ? $request->url() : '';
    }
}
