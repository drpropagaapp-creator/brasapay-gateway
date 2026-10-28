<?php

namespace App\Http\Middleware;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Models\SubscriptionPlan;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brasa Enterprise: no domínio white-label, cada rota /checkout/* tem um campo que identifica o recurso
 * em que ela age, e esse recurso precisa ser do dono do domínio. Rota fora do mapa = 404 (negado por padrão).
 * Toda referência extra (oferta, plano, bump, pedido) também precisa ser do mesmo seller.
 * Roda depois da sessão (os tokens de Pix/boleto ficam nela). Fora de domínio white-label não faz nada.
 */
class EnforceWhiteLabelOwnership
{
    /** caminho (método:path) => campo obrigatório que identifica o recurso */
    private const ROUTES = [
        'POST:checkout' => 'product_id',
        'POST:checkout/validate-coupon' => 'product_id',
        'POST:checkout/shipping-quote' => 'product_id',
        'GET:checkout/pix' => 'pix_token',
        'GET:checkout/boleto' => 'pix_token',
        'GET:checkout/order-status' => 'pix_token',
        'GET:checkout/obrigado' => 'upsell_token',
        'GET:checkout/upsell' => 'upsell_token',
        'GET:checkout/downsell' => 'upsell_token',
        'POST:checkout/upsell/accept' => 'upsell_token',
        'POST:checkout/upsell/decline' => 'upsell_token',
        'POST:checkout/downsell/accept' => 'upsell_token',
        'POST:checkout/downsell/decline' => 'upsell_token',
        'POST:checkout/pixel/events' => 'checkout_session_token',
        'POST:checkout/pixel/purchase-ack' => 'order_id',
        // Helpers de tokenização de cartão: não recebem produto nem pedido.
        'POST:checkout/pagarme-tokenize-sink' => null,
        'POST:checkout/cielo-sop-token' => null,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->attributes->get('whitelabel_tenant_id');
        $path = trim($request->path(), '/');
        if ($tenantId !== null && str_starts_with($path, 'checkout')) {
            $key = strtoupper($request->method()).':'.$path;
            abort_unless(array_key_exists($key, self::ROUTES) && $this->allowed($request, (int) $tenantId, self::ROUTES[$key]), 404);
        }

        return $next($request);
    }

    private function allowed(Request $request, int $tenantId, ?string $primary): bool
    {
        $owned = Product::query()->where('tenant_id', $tenantId)->select('id');

        // 1) O recurso em que a rota age.
        $ok = match ($primary) {
            null => true,
            'product_id' => $this->scalar($request->input('product_id'))
                && Product::query()->whereKey((string) $request->input('product_id'))->where('tenant_id', $tenantId)->exists(),
            'order_id' => $this->ownsOrder($request->input('order_id'), $tenantId),
            'checkout_session_token' => $this->scalar($request->input('checkout_session_token'))
                && CheckoutSession::query()->where('session_token', (string) $request->input('checkout_session_token'))->where('tenant_id', $tenantId)->exists(),
            'pix_token' => $this->ownsPixToken($request, $tenantId),
            'upsell_token' => $this->ownsUpsellToken($request->input('token'), $tenantId),
            default => false,
        };
        if (! $ok) {
            return false;
        }

        // 2) Referências adicionais: se vierem, precisam ser escalares e do mesmo seller.
        foreach (['product_id', 'product_offer_id', 'subscription_plan_id', 'order_id', 'checkout_session_token', 'token'] as $field) {
            $v = $request->input($field);
            if ($v !== null && $v !== '' && ! $this->scalar($v)) {
                return false;
            }
        }
        if ($request->filled('product_id') && $primary !== 'product_id'
            && ! Product::query()->whereKey((string) $request->input('product_id'))->where('tenant_id', $tenantId)->exists()) {
            return false;
        }
        if ($request->filled('product_offer_id') && ! ProductOffer::query()->whereKey((int) $request->input('product_offer_id'))->whereIn('product_id', $owned)->exists()) {
            return false;
        }
        if ($request->filled('subscription_plan_id') && ! SubscriptionPlan::query()->whereKey((int) $request->input('subscription_plan_id'))->whereIn('product_id', $owned)->exists()) {
            return false;
        }
        if ($request->filled('order_id') && $primary !== 'order_id' && ! $this->ownsOrder($request->input('order_id'), $tenantId)) {
            return false;
        }
        if ($request->filled('checkout_session_token') && $primary !== 'checkout_session_token'
            && ! CheckoutSession::query()->where('session_token', (string) $request->input('checkout_session_token'))->where('tenant_id', $tenantId)->exists()) {
            return false;
        }
        $bumps = $request->input('order_bump_ids');
        if ($bumps !== null) {
            if (! is_array($bumps) || count($bumps) > 20) {
                return false;
            }
            $ids = array_values(array_filter($bumps, fn ($b) => is_scalar($b) && ctype_digit((string) $b)));
            if (count($ids) !== count($bumps) || ($ids !== [] && ProductOrderBump::query()->whereIn('id', $ids)->whereIn('product_id', $owned)->count() !== count(array_unique($ids)))) {
                return false;
            }
        }
        // Não há rota de checkout mapeada que aceite listas de itens; qualquer "items" é recusado.
        return ! $request->has('items');
    }

    private function scalar(mixed $v): bool
    {
        return is_string($v) || is_int($v);
    }

    private function ownsOrder(mixed $id, int $tenantId): bool
    {
        return $this->scalar($id) && ctype_digit((string) $id) && Order::query()->whereKey((int) $id)->where('tenant_id', $tenantId)->exists();
    }

    /** Pix/boleto: o token vive na sessão (presa a este host) e aponta o pedido. */
    private function ownsPixToken(Request $request, int $tenantId): bool
    {
        $token = $request->input('token');
        if (! is_string($token) || $token === '' || ! $request->hasSession()) {
            return false;
        }
        $pix = $request->session()->get('pix_display.'.$token);

        return is_array($pix) && $this->ownsOrder($pix['order_id'] ?? null, $tenantId);
    }

    /** Upsell/downsell/obrigado: o token fica no cache e aponta o pedido. */
    private function ownsUpsellToken(mixed $token, int $tenantId): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }
        $data = Cache::get('upsell_token.'.$token);

        return is_array($data) && $this->ownsOrder($data['order_id'] ?? null, $tenantId);
    }
}
