<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiApplication;
use App\Models\ApiCheckoutSession;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Services\Api\ApiCardService;
use App\Support\ApiHostedCheckoutPricing;
use App\Services\ApiPixAccess;
use App\Services\MerchantOperationalGuard;
use App\Services\MinimumChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckoutSessionsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $app = $request->attributes->get('api_application');
        if (! $app instanceof ApiApplication) {
            abort(500, 'API application not resolved');
        }
        if (! ApiPixAccess::effectiveForTenant($app->tenant_id)) {
            return response()->json(['message' => 'API PIX disabled for this tenant.'], 403);
        }
        MerchantOperationalGuard::assertCanAcceptPayments((int) $app->tenant_id);

        $apiMin = app(MinimumChargeService::class)->apiPixMinimumBrlForTenant((int) $app->tenant_id);
        $validated = $request->validate([
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.cpf' => ['nullable', 'string', 'max:14'],
            'customer.phone' => ['nullable', 'string', 'max:24'],
            'amount' => ['required', 'numeric', 'min:'.$apiMin],
            'currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'product_id' => ['nullable', 'string', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'integer', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'metadata' => ['nullable', 'array'],
            'return_url' => ['nullable', 'string', 'url', 'max:512'],
            'expires_in' => ['nullable', 'integer', 'min:5', 'max:1440'],
            // Guardar o cartão para o sistema do seller cobrar as próximas vezes (POST /payments/card/saved).
            'save_card' => ['nullable', 'boolean'],
            // Métodos que a página mostra (vazio = todos os configurados na aplicação).
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string', 'in:pix,pix_auto,card,boleto'],
        ]);
        $saveCard = (bool) ($validated['save_card'] ?? false);
        $methods = array_values(array_unique($validated['payment_methods'] ?? []));
        if ($saveCard && $methods !== [] && ! in_array('card', $methods, true)) {
            return response()->json(['message' => 'save_card exige o cartão entre os payment_methods.'], 422);
        }

        $tenantId = $app->tenant_id;
        $chargeBrl = null;
        if (! empty($validated['product_id'])) {
            $product = Product::where('id', $validated['product_id'])->where('tenant_id', $tenantId)->first();
            if (! $product) {
                return response()->json(['message' => 'Produto não encontrado.'], 422);
            }
            if (! $product->isAvailableForPurchase()) {
                return response()->json(['message' => 'Produto indisponível para compra.'], 422);
            }
            $offerId = isset($validated['product_offer_id']) ? (int) $validated['product_offer_id'] : null;
            $planId = isset($validated['subscription_plan_id']) ? (int) $validated['subscription_plan_id'] : null;
            if ($offerId) {
                if (! ProductOffer::where('id', $offerId)->where('product_id', $product->id)->exists()) {
                    return response()->json(['message' => 'Oferta inválida para este produto.'], 422);
                }
            }
            if ($planId) {
                if (! SubscriptionPlan::where('id', $planId)->where('product_id', $product->id)->exists()) {
                    return response()->json(['message' => 'Plano inválido para este produto.'], 422);
                }
            }
            $expectedBrl = ApiHostedCheckoutPricing::expectedAmountBrl(
                $tenantId,
                (string) $validated['product_id'],
                $offerId ?: null,
                $planId ?: null,
            );
            if ($expectedBrl === null) {
                return response()->json(['message' => 'Não foi possível validar o preço do produto.'], 422);
            }
            $requestCurrency = strtoupper((string) ($validated['currency'] ?? 'BRL'));
            $requestedBrl = ApiHostedCheckoutPricing::amountToBrl((float) $validated['amount'], $requestCurrency);
            if (abs($expectedBrl - $requestedBrl) > 0.02) {
                return response()->json(['message' => 'Valor não corresponde ao preço do produto.'], 422);
            }
            $chargeBrl = $expectedBrl;
        }

        if ($chargeBrl === null) {
            $requestCurrency = strtoupper((string) ($validated['currency'] ?? 'BRL'));
            $chargeBrl = ApiHostedCheckoutPricing::amountToBrl((float) $validated['amount'], $requestCurrency);
        }

        app(MinimumChargeService::class)->assertApiPayment($chargeBrl, (int) $tenantId);

        if ($saveCard) {
            // Confere agora (e não na página) se a adquirente que recebe cartão hoje guarda cartão.
            $cards = app(ApiCardService::class);
            $product = ! empty($validated['product_id']) ? Product::find($validated['product_id']) : null;
            $gateway = $cards->gatewayFor($app, 'card', $product);
            if ($gateway === null || ! $cards->hostedCardReady((int) $tenantId, $gateway, $product !== null)) {
                return response()->json(['message' => 'Cartão indisponível no checkout desta conta. Confira a adquirente de cartão em Integrações.'], 422);
            }
            if (! $cards->gatewaySavesCards($gateway)) {
                return response()->json([
                    'message' => "A adquirente de cartão desta conta ({$gateway}) ainda não guarda cartão. Crie a sessão sem save_card e cobre pelo link a cada vez.",
                    'gateway' => $gateway,
                ], 422);
            }
        }

        $expiresIn = (int) ($validated['expires_in'] ?? 30);
        $expiresAt = now()->addMinutes($expiresIn);
        $sessionToken = Str::uuid()->toString();

        $session = ApiCheckoutSession::create([
            'api_application_id' => $app->id,
            'tenant_id' => $tenantId,
            'session_token' => $sessionToken,
            'customer' => [
                'email' => $validated['customer']['email'],
                'name' => $validated['customer']['name'] ?? $validated['customer']['email'],
                'cpf' => $validated['customer']['cpf'] ?? null,
                'phone' => $validated['customer']['phone'] ?? null,
            ],
            'amount' => (float) $validated['amount'],
            'currency' => strtoupper((string) ($validated['currency'] ?? 'BRL')),
            'product_id' => $validated['product_id'] ?? null,
            'product_offer_id' => $validated['product_offer_id'] ?? null,
            'subscription_plan_id' => $validated['subscription_plan_id'] ?? null,
            'metadata' => $validated['metadata'] ?? [],
            'return_url' => $validated['return_url'] ?? ($app->default_return_url ?: null),
            'expires_at' => $expiresAt,
            'save_card' => $saveCard,
            'payment_methods' => $methods !== [] ? $methods : null,
        ]);

        $checkoutUrl = url('/api-checkout/' . $sessionToken);

        return response()->json([
            'session_id' => (string) $session->id,
            'checkout_url' => $checkoutUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201);
    }
}
