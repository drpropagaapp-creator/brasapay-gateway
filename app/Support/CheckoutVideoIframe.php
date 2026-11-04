<?php

namespace App\Support;

/**
 * Player de vídeo próprio (iframe) no checkout, ex.: https://drpropaga-vsl.vercel.app/p/xxxx
 *
 * O vendedor pode colar o código <iframe ...> inteiro ou só o link. Guardamos APENAS a URL
 * (https, host na lista permitida) — o HTML do vendedor nunca é renderizado.
 * Hosts extras: env CHECKOUT_VIDEO_IFRAME_HOSTS="host1.com,host2.com" (aceita "*.dominio.com").
 */
final class CheckoutVideoIframe
{
    /** @var list<string> */
    private const DEFAULT_HOSTS = ['drpropaga-vsl.vercel.app'];

    /**
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = self::DEFAULT_HOSTS;
        $extra = (string) env('CHECKOUT_VIDEO_IFRAME_HOSTS', '');
        foreach (explode(',', $extra) as $h) {
            $h = strtolower(trim($h));
            if ($h !== '' && preg_match('/^(\*\.)?[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $h)) {
                $hosts[] = $h;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Fontes para a diretiva CSP frame-src.
     */
    public static function cspSources(): string
    {
        return implode(' ', array_map(static fn (string $h) => 'https://'.$h, self::allowedHosts()));
    }

    /**
     * Aceita link direto ou código <iframe>; retorna URL https permitida ou '' se inválida.
     */
    public static function normalize(?string $input): string
    {
        $raw = trim((string) $input);
        if ($raw === '') {
            return '';
        }

        if (stripos($raw, '<iframe') !== false) {
            if (! preg_match('/<iframe\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/is', $raw, $m)) {
                return '';
            }
            $raw = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5);
        }

        if (mb_strlen($raw) > 2000 || preg_match('/\s/', $raw)) {
            return '';
        }

        $parts = parse_url($raw);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! self::hostAllowed($host)) {
            return '';
        }

        return $raw;
    }

    private static function hostAllowed(string $host): bool
    {
        foreach (self::allowedHosts() as $allowed) {
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1); // ".dominio.com"
                if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                    return true;
                }
            } elseif ($host === $allowed) {
                return true;
            }
        }

        return false;
    }
}
