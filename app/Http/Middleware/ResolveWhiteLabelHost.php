<?php

namespace App\Http\Middleware;

use App\Models\EnterpriseDomain;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Services\Enterprise\EnterpriseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brasa Enterprise: num domínio white-label ativo, a plataforma serve só o checkout dos produtos
 * daquele seller (e os arquivos estáticos). Painel, login, área administrativa e checkout de outros
 * sellers dão 404 nesse host. Cookies continuam presos ao host (sem SESSION_DOMAIN), então uma sessão
 * aberta no domínio principal nunca é enviada ao domínio do cliente.
 */
class ResolveWhiteLabelHost
{
    /** Caminhos permitidos no domínio white-label. */
    private const ALLOWED = '#^(c/[a-z0-9]{6,16}|checkout(/.*)?|\.well-known/brasapay-domain/[a-z0-9]{40}|build/.+|storage/.+|images/.+|favicon\.ico|robots\.txt|up)$#';

    public function __construct(private EnterpriseService $enterprise) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower((string) $request->getHost());
        $path = trim($request->path(), '/');

        // Prova de HTTPS da verificação: responde o token em qualquer host cadastrado (ativo ou pendente).
        if (preg_match('#^\.well-known/brasapay-domain/([a-z0-9]{40})$#', $path, $m)) {
            $ok = EnterpriseDomain::query()->where('host', $host)->where('verification_token', $m[1])
                ->where('status', '!=', EnterpriseDomain::STATUS_REMOVED)->exists();

            return $ok ? response($m[1], 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']) : abort(404);
        }

        $state = $this->enterprise->hostState($host);
        if ($state === 0) {
            return $next($request);
        }
        // Cadastrado mas sem poder servir (pendente, desativado, conta suspensa): nada da plataforma aparece.
        if ($state < 0) {
            abort(404);
        }
        $tenantId = $state;

        if (! preg_match(self::ALLOWED, $path)) {
            abort(404);
        }
        if (preg_match('#^c/([a-z0-9]{6,16})$#', $path, $m) && ! $this->slugBelongsTo($m[1], $tenantId)) {
            abort(404);
        }

        $request->attributes->set('whitelabel_tenant_id', $tenantId);

        return $next($request);
    }

    private function slugBelongsTo(string $slug, int $tenantId): bool
    {
        if (Product::query()->where('checkout_slug', $slug)->where('tenant_id', $tenantId)->exists()) {
            return true;
        }
        $productIds = Product::query()->where('tenant_id', $tenantId)->select('id');

        return ProductOffer::query()->where('checkout_slug', $slug)->whereIn('product_id', $productIds)->exists()
            || SubscriptionPlan::query()->where('checkout_slug', $slug)->whereIn('product_id', $productIds)->exists();
    }
}
