<?php

namespace App\Http\Middleware;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brasa Enterprise: no domínio white-label, toda rota /checkout/* precisa referenciar algo do próprio
 * seller (produto, oferta, plano, pedido, sessão de checkout ou token de pós-compra) e nada de outro.
 * Roda depois da sessão (os tokens de Pix/boleto ficam nela). Fora de domínio white-label não faz nada.
 */
class EnforceWhiteLabelOwnership
{
    /** Rotas de checkout que não carregam produto/pedido (helpers de tokenização de cartão). */
    private const NO_DATA = ['checkout/pagarme-tokenize-sink', 'checkout/cielo-sop-token'];

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->attributes->get('whitelabel_tenant_id');
        $path = trim($request->path(), '/');
        if ($tenantId !== null && str_starts_with($path, 'checkout') && ! in_array($path, self::NO_DATA, true)) {
            abort_unless($this->belongsTo($request, (int) $tenantId), 404);
        }

        return $next($request);
    }

    private function belongsTo(Request $request, int $tenantId): bool
    {
        $checks = [];
        $owned = Product::query()->where('tenant_id', $tenantId)->select('id');

        $product = $request->input('product_id');
        if (is_scalar($product) && $product !== '') {
            $checks[] = Product::query()->whereKey((string) $product)->where('tenant_id', $tenantId)->exists();
        }
        $offer = $request->input('product_offer_id');
        if (is_scalar($offer) && $offer !== '') {
            $checks[] = ProductOffer::query()->whereKey((int) $offer)->whereIn('product_id', $owned)->exists();
        }
        $plan = $request->input('subscription_plan_id');
        if (is_scalar($plan) && $plan !== '') {
            $checks[] = SubscriptionPlan::query()->whereKey((int) $plan)->whereIn('product_id', $owned)->exists();
        }
        foreach (['order_id', 'order'] as $key) {
            $order = $request->input($key) ?? $request->route($key);
            if (is_scalar($order) && $order !== '') {
                $checks[] = ctype_digit((string) $order) && Order::query()->whereKey((int) $order)->where('tenant_id', $tenantId)->exists();
            }
        }
        $session = $request->input('checkout_session_token');
        if (is_string($session) && $session !== '') {
            $checks[] = CheckoutSession::query()->where('session_token', $session)->where('tenant_id', $tenantId)->exists();
        }
        $token = $request->input('token');
        if (is_string($token) && $token !== '') {
            // Upsell/obrigado: token no cache aponta o pedido. Pix/boleto: token na sessão (presa a este host).
            $upsell = Cache::get('upsell_token.'.$token);
            $pix = $request->hasSession() ? $request->session()->get('pix_display.'.$token) : null;
            $orderId = is_array($upsell) ? ($upsell['order_id'] ?? null) : (is_array($pix) ? ($pix['order_id'] ?? null) : null);
            $checks[] = $orderId !== null
                ? Order::query()->whereKey((int) $orderId)->where('tenant_id', $tenantId)->exists()
                : is_array($pix);
        }

        return $checks !== [] && ! in_array(false, $checks, true);
    }
}
