<?php

namespace App\Http\Middleware;

use App\Support\CheckoutEmbed;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureCheckoutIframeSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $partition = CheckoutEmbed::shouldPartitionSession($request);
        if ($partition) {
            config([
                'session.same_site' => 'none',
                'session.secure' => true,
                'session.partitioned' => true,
            ]);
        }

        $response = $next($request);

        if (! $partition && $request->secure()) {
            self::expireStalePartitionedCopies($request);
        }

        return $response;
    }

    /**
     * Expira a cópia particionada antiga que ainda esteja no navegador (gravada antes desta
     * correção ao abrir um checkout ou a prévia do builder). Com ela o navegador manda dois
     * cookies de sessão com o mesmo nome e o login/2FA perde a sessão.
     *
     * Vai como header bruto: no ResponseHeaderBag a chave do cookie é domínio+caminho+nome
     * (sem "Partitioned"), então um setCookie() aqui substituiria o cookie de sessão real.
     */
    private static function expireStalePartitionedCopies(Request $request): void
    {
        $names = CheckoutEmbed::duplicatedCookieNames($request);
        if ($names === [] || headers_sent()) {
            return;
        }

        $path = (string) config('session.path', '/');
        foreach ($names as $name) {
            header(sprintf(
                'Set-Cookie: %s=deleted; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; Path=%s; Secure; SameSite=None; Partitioned',
                $name,
                $path
            ), false);
        }
    }
}
