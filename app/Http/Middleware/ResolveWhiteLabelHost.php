<?php

namespace App\Http\Middleware;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Services\Enterprise\EnterpriseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    private const ALLOWED = '#^(c/[a-z0-9]{6,16}|checkout(/.*)?|build/.+|storage/.+|images/.+|favicon\.ico|robots\.txt|up)$#';

    public function __construct(private EnterpriseService $enterprise) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower((string) $request->getHost());
        $path = trim($request->path(), '/');

        // Prova de HTTPS da verificação (rota própria): liberada em qualquer host cadastrado.
        if (preg_match('#^\.well-known/brasapay-domain/[a-z0-9]{40}$#', $path)) {
            return $next($request);
        }

        $state = $this->enterprise->hostState($host);
        if ($state === 0) {
            return $next($request);
        }
        // Domínio comprovado mas sem poder servir (desativado, conta suspensa): nada da plataforma aparece.
        if ($state < 0) {
            abort(404);
        }
        $tenantId = $state;

        if (! preg_match(self::ALLOWED, $path)) {
            abort(404);
        }
        if (preg_match('#^c/([a-z0-9]{6,16})$#', $path, $m)) {
            abort_unless($this->slugBelongsTo($m[1], $tenantId), 404);
        }
        // Rotas /checkout/*: a posse do produto/pedido é conferida em EnforceWhiteLabelOwnership (depois da sessão).

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
