<?php

namespace App\Services\Api;

use App\Gateways\Contracts\SavesCards;
use App\Gateways\GatewayRegistry;
use App\Models\ApiApplication;
use App\Models\ApiCheckoutSession;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\SavedPaymentMethod;
use App\Services\PaymentService;

/**
 * Cartão pela API (sistema do seller):
 * - descobre qual adquirente recebe cartão agora (a fixada na aplicação ou a da ordem da conta);
 * - diz se o checkout hospedado sabe pedir o cartão nessa adquirente e se ela guarda cartão;
 * - guarda o cartão da primeira cobrança e libera o reuso só depois que essa cobrança foi paga.
 */
class ApiCardService
{
    /** Adquirentes cujo formulário de cartão o checkout hospedado (/api-checkout) sabe montar. */
    public const HOSTED_CARD_GATEWAYS = ['stripe', 'efi', 'pagarme', 'cielo'];

    /**
     * @return array<string, mixed>
     */
    public function gatewayConfig(ApiApplication $app): array
    {
        $pg = $app->payment_gateways ?? ApiApplication::defaultPaymentGateways();

        return is_array($pg) ? $pg : ApiApplication::defaultPaymentGateways();
    }

    /** Adquirente que recebe o método agora para esta aplicação (null = método indisponível). */
    public function gatewayFor(ApiApplication $app, string $method, ?Product $product = null): ?string
    {
        return app(PaymentService::class)->getFirstAvailableGatewayForMethod(
            (int) $app->tenant_id,
            $method,
            $product,
            $this->gatewayConfig($app),
        );
    }

    /**
     * Adquirente do cartão na página hospedada: a fixada na aplicação; sem ela, a que recebe cartão
     * hoje na conta — só quando a sessão pediu cartão explicitamente (payment_methods), para não
     * passar a mostrar cartão em checkouts de quem nunca configurou.
     */
    public function hostedCardGateway(ApiApplication $app, ApiCheckoutSession $session, ?Product $product): ?string
    {
        if (! $session->allowsMethod('card')) {
            return null;
        }
        $pg = $this->gatewayConfig($app);
        if (! empty($pg['card'])) {
            return (string) $pg['card'];
        }
        $asked = is_array($session->payment_methods) && in_array('card', $session->payment_methods, true);

        return $asked ? $this->gatewayFor($app, 'card', $product) : null;
    }

    public function gatewaySavesCards(?string $gateway): bool
    {
        if ($gateway === null || $gateway === '') {
            return false;
        }

        return GatewayRegistry::driver($gateway) instanceof SavesCards;
    }

    /** O checkout hospedado consegue pedir o cartão nesta adquirente (chaves públicas configuradas)? */
    public function hostedCardReady(int $tenantId, ?string $gateway, bool $hasProduct): bool
    {
        if ($gateway === null || ! in_array($gateway, self::HOSTED_CARD_GATEWAYS, true)) {
            return false;
        }
        $cred = GatewayCredential::resolveForPayment($tenantId, $gateway);
        $c = $cred ? $cred->getDecryptedCredentials() : [];

        return match ($gateway) {
            'stripe' => trim((string) ($c['publishable_key'] ?? '')) !== '',
            'pagarme' => trim((string) ($c['public_key'] ?? '')) !== '',
            'efi' => trim((string) ($c['payee_code'] ?? '')) !== ''
                && ($path = (string) ($c['certificate_path'] ?? '')) !== '' && is_file($path),
            // Cielo no checkout hospedado exige produto (o valor vem do produto).
            'cielo' => $hasProduct && trim((string) ($c['merchant_id'] ?? '')) !== '' && trim((string) ($c['merchant_key'] ?? '')) !== '',
            default => false,
        };
    }

    /**
     * O que esta aplicação consegue receber agora, por método.
     *
     * @return array<string, array<string, mixed>>
     */
    public function capabilities(ApiApplication $app): array
    {
        $tenantId = (int) $app->tenant_id;
        $out = [];
        foreach (['pix', 'boleto'] as $method) {
            $gw = $this->gatewayFor($app, $method);
            $out[$method] = ['available' => $gw !== null, 'gateway' => $gw];
        }
        $card = $this->gatewayFor($app, 'card');
        $out['card'] = [
            'available' => $card !== null,
            'gateway' => $card,
            'hosted_checkout' => $this->hostedCardReady($tenantId, $card, false),
            'saved_cards' => $this->gatewaySavesCards($card),
        ];

        return $out;
    }

    /**
     * Guarda o cartão devolvido pela adquirente na primeira cobrança. Fica "pendente" até o
     * pedido de origem ser pago (cobrança recusada nunca vira cartão utilizável).
     *
     * @param  array<string, mixed>  $saved
     */
    public function remember(Order $order, string $gateway, array $saved): ?SavedPaymentMethod
    {
        $pm = trim((string) ($saved['payment_method_id'] ?? ''));
        if ($pm === '' || $order->api_application_id === null) {
            return null;
        }

        return SavedPaymentMethod::firstOrCreate(
            ['source_order_id' => $order->id, 'gateway_payment_method_id' => $pm],
            [
                'tenant_id' => $order->tenant_id,
                'user_id' => $order->user_id,
                'api_application_id' => $order->api_application_id,
                'gateway' => $gateway,
                'gateway_customer_id' => ($saved['customer_id'] ?? '') !== '' ? (string) $saved['customer_id'] : null,
                'last_four' => isset($saved['last_four']) ? substr((string) $saved['last_four'], -4) : null,
                'brand' => isset($saved['brand']) ? substr((string) $saved['brand'], 0, 32) : 'card',
                'exp_month' => isset($saved['exp_month']) ? (int) $saved['exp_month'] : null,
                'exp_year' => isset($saved['exp_year']) ? (int) $saved['exp_year'] : null,
                'type' => 'card',
            ],
        );
    }

    /** Cartão desta aplicação (outra aplicação ou o checkout comum nunca enxergam). */
    public function find(ApiApplication $app, int|string $id): ?SavedPaymentMethod
    {
        if (! ctype_digit((string) $id)) {
            return null;
        }

        return SavedPaymentMethod::query()
            ->where('id', (int) $id)
            ->where('api_application_id', $app->id)
            ->where('tenant_id', $app->tenant_id)
            ->first();
    }

    public function forOrder(Order $order): ?SavedPaymentMethod
    {
        if ($order->api_application_id === null) {
            return null;
        }

        return SavedPaymentMethod::query()
            ->where('source_order_id', $order->id)
            ->where('api_application_id', $order->api_application_id)
            ->latest('id')
            ->first();
    }

    /** Só cobra de novo um cartão cuja primeira cobrança foi paga. */
    public function isReady(SavedPaymentMethod $card): bool
    {
        if ($card->source_order_id === null) {
            return false;
        }

        return Order::query()->whereKey($card->source_order_id)->where('status', 'completed')->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(SavedPaymentMethod $card): array
    {
        return [
            'id' => (string) $card->id,
            'status' => $this->isReady($card) ? 'active' : 'pending',
            'gateway' => $card->gateway,
            'brand' => $card->brand,
            'last_four' => $card->last_four,
            'exp_month' => $card->exp_month !== null ? (int) $card->exp_month : null,
            'exp_year' => $card->exp_year !== null ? (int) $card->exp_year : null,
            'source_order_id' => $card->source_order_id,
            'created_at' => $card->created_at?->toIso8601String(),
        ];
    }

    /** Remove o cartão: desvincula na adquirente (quando ela sabe) e apaga daqui. */
    public function forget(SavedPaymentMethod $card): void
    {
        $driver = GatewayRegistry::driver((string) $card->gateway);
        $pm = trim((string) $card->gateway_payment_method_id);
        if ($driver instanceof SavesCards && $pm !== '') {
            $cred = GatewayCredential::resolveForPayment($card->tenant_id !== null ? (int) $card->tenant_id : null, (string) $card->gateway);
            if ($cred) {
                $driver->forgetSavedCard($cred->getDecryptedCredentials(), $pm);
            }
        }
        $card->delete();
    }
}
