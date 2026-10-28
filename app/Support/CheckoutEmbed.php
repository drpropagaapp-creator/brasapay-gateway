<?php

namespace App\Support;

use Illuminate\Http\Request;

class CheckoutEmbed
{
    public static function isEnabled(): bool
    {
        return (bool) config('getfy.checkout_embed.enabled', true);
    }

    public static function isEmbeddableRequest(Request $request): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        return $request->is('c/*')
            || $request->is('checkout/*')
            || $request->is('api-checkout')
            || $request->is('api-checkout/*')
            || $request->is('api/checkout/track');
    }

    /**
     * Cookie de sessão particionado (SameSite=None; Partitioned) só quando o checkout roda
     * dentro de um iframe de outro site. Em acesso direto ou na prévia do builder (iframe da
     * própria plataforma) o cookie particionado vira um SEGUNDO "getfy-session" no navegador:
     * depois do login (que troca o ID da sessão) o navegador manda os dois e o PHP pode ler o
     * antigo — a sessão some (ex.: "Sessão de verificação expirada" no 2FA).
     */
    public static function shouldPartitionSession(Request $request): bool
    {
        if (! self::isEmbeddableRequest($request) || $request->boolean('preview')) {
            return false;
        }

        // Requisições feitas de dentro do iframe de terceiro (o front marca o header).
        if ($request->header('X-Checkout-Embed') === '1') {
            return true;
        }

        $dest = strtolower((string) $request->header('Sec-Fetch-Dest', ''));
        if ($dest === '') {
            // Navegador sem Fetch Metadata também não suporta CHIPS: o atributo é ignorado
            // e não há cookie duplicado; mantém o comportamento antigo (funciona em iframe).
            return true;
        }

        return in_array($dest, ['iframe', 'frame', 'embed', 'object'], true);
    }

    /** Cookies que o checkout embutido grava em versão particionada. */
    public static function partitionedCookieNames(): array
    {
        return [(string) config('session.cookie'), 'XSRF-TOKEN'];
    }

    /**
     * Nomes de cookie que chegaram duplicados (variante particionada antiga + a normal).
     *
     * @return list<string>
     */
    public static function duplicatedCookieNames(Request $request): array
    {
        $header = (string) $request->headers->get('cookie', '');
        if ($header === '') {
            return [];
        }

        $dupes = [];
        foreach (self::partitionedCookieNames() as $name) {
            if ($name !== '' && preg_match_all('/(?:^|;\s*)'.preg_quote($name, '/').'=/', $header) > 1) {
                $dupes[] = $name;
            }
        }

        return $dupes;
    }
}
