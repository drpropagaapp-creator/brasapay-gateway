<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\OrderRejected;
use App\Exceptions\AmbiguousCardChargeException;
use App\Gateways\Stripe\StripeDriver;
use App\Http\Controllers\Controller;
use App\Services\Api\ApiAuthContext;
use App\Services\Api\ApiCardService;
use App\Services\Api\ApiIdempotencyService;
use App\Services\PaymentService;
use App\Support\ApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cartão pela API, para o sistema do seller cobrar assinaturas no cartão:
 * - GET  /v1/payment-methods        o que a conta recebe agora (e se a adquirente de cartão guarda cartão);
 * - POST /v1/payments/card/saved    cobra um cartão guardado no checkout hospedado (save_card);
 * - GET/DELETE /v1/saved-cards/{id} consulta / remove o cartão guardado.
 */
class SavedCardsController extends Controller
{
    use Concerns\HandlesApiPayments;

    public function __construct(private ApiCardService $cards) {}

    public function capabilities(Request $request): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(ApiScopes::PAYMENTS_READ)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }

        return response()->json(['methods' => $this->cards->capabilities($ctx->application)]);
    }

    public function show(Request $request, string $savedCard): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(ApiScopes::PAYMENTS_READ)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }
        $card = $this->cards->find($ctx->application, $savedCard);
        if (! $card) {
            return response()->json(['message' => 'Cartão salvo não encontrado.'], 404);
        }

        return response()->json($this->cards->serialize($card));
    }

    public function destroy(Request $request, string $savedCard): JsonResponse
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope(ApiScopes::PAYMENTS_WRITE)) {
            return response()->json(['message' => 'Insufficient API key permissions.'], 403);
        }
        $card = $this->cards->find($ctx->application, $savedCard);
        if (! $card) {
            return response()->json(['message' => 'Cartão salvo não encontrado.'], 404);
        }
        $this->cards->forget($card);

        return response()->json(['deleted' => true]);
    }

    public function charge(Request $request, ApiIdempotencyService $idempotency): JsonResponse
    {
        $ctx = $this->resolvePaymentContext($request, ApiScopes::PAYMENTS_WRITE);
        $validated = $request->validate([
            'saved_card_id' => ['required', 'string', 'max:32'],
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.cpf' => ['nullable', 'string', 'max:14'],
            'customer.phone' => ['nullable', 'string', 'max:24'],
            'amount' => $this->apiPaymentAmountRules((int) $ctx->application->tenant_id),
            'metadata' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $idemKey = $request->input('idempotency_key') ?: $request->header('Idempotency-Key');

        return $idempotency->handle(
            $ctx->application,
            $ctx->apiKey,
            is_string($idemKey) ? $idemKey : null,
            $request->getContent() ?: json_encode($validated),
            fn () => $this->doCharge($request, $ctx, $validated),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function doCharge(Request $request, ApiAuthContext $ctx, array $validated): JsonResponse
    {
        $app = $ctx->application;
        $card = $this->cards->find($app, (string) $validated['saved_card_id']);
        if (! $card) {
            return response()->json(['message' => 'Cartão salvo não encontrado.'], 404);
        }
        if (! $this->cards->isReady($card)) {
            return response()->json(['message' => 'Este cartão ainda não foi confirmado: a primeira cobrança dele não foi paga.'], 422);
        }

        $userConsumer = $this->validateCustomerAndGetUser($request, $app, $ctx);
        if ((int) $userConsumer['user']->id !== (int) $card->user_id) {
            return response()->json(['message' => 'Este cartão salvo é de outro cliente.'], 422);
        }

        $metadata = $validated['metadata'] ?? [];
        $metadata['source'] = 'api';
        $metadata['saved_card_id'] = (string) $card->id;

        $ctxData = $this->createOrderForApi(
            $request,
            $app,
            (float) $validated['amount'],
            'BRL',
            null,
            null,
            null,
            'card',
            $metadata,
            $userConsumer,
            $ctx,
        );
        $order = $ctxData['order'];

        $payload = [
            'payment_token' => (string) $card->gateway_payment_method_id,
            'card_mask' => $card->last_four ? '**** '.$card->last_four : null,
            'currency' => 'brl',
            'customer_id' => $card->gateway_customer_id,
            'off_session' => true,
        ];
        // O cartão só existe na adquirente que o guardou: nunca tenta outra.
        $gatewayConfig = array_merge(
            is_array($ctxData['gateway_config']) ? $ctxData['gateway_config'] : [],
            ['card' => $card->gateway, 'card_redundancy' => []],
        );

        try {
            event(new OrderPending($order));
            $result = app(PaymentService::class)->createCardPayment($order, null, $ctxData['consumer'], $payload, $gatewayConfig, [(string) $card->gateway]);
        } catch (AmbiguousCardChargeException $e) {
            // A cobrança pode ter passado: o pedido fica para a conciliação, nunca é apagado.
            return response()->json([
                'order_id' => $order->id,
                'status' => 'processing',
                'message' => $e->getMessage(),
            ], 202);
        } catch (\Throwable $e) {
            $order->delete();
            $message = $e->getMessage() ?: 'Falha no pagamento com cartão.';

            return response()->json(array_filter([
                'message' => $message,
                'code' => $message === StripeDriver::OFF_SESSION_AUTH_MESSAGE ? 'authentication_required' : 'card_failed',
            ]), 422);
        }

        $status = is_string($result['status'] ?? null) ? strtolower(trim((string) $result['status'])) : 'pending';
        if (in_array($status, ['paid', 'settled', 'approved', 'completed'], true)) {
            $order->update(['status' => 'completed', 'payment_method' => 'card']);
            event(new OrderCompleted($order->fresh()));
            $status = 'completed';
        } elseif (in_array($status, ['rejected', 'refused', 'cancelled', 'canceled', 'failed'], true)) {
            $order->update(['status' => 'rejected']);
            event(new OrderRejected($order->fresh()));

            return response()->json([
                'order_id' => $order->id,
                'status' => 'rejected',
                'code' => 'card_declined',
                'message' => 'Cartão recusado.',
            ], 422);
        }

        return response()->json([
            'order_id' => $order->id,
            'transaction_id' => $result['transaction_id'] ?? null,
            'status' => $status,
        ], 201);
    }
}
