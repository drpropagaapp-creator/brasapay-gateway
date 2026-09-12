<?php

namespace App\Services\CajuPay;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Services\PlatformCardInstallments;
use App\Support\CardInstallments;
use App\Support\CajuPayBrowserSdk;
use Illuminate\Support\Facades\Http;

class CajuPaySdkCheckoutService
{
    /**
     * Flags de parcelamento Cartão Brasil para POST /api/sdk/v1/checkout/sessions.
     *
     * @param  array<string, mixed>  $checkoutConfig
     * @return array{allow_card_installments: bool, card_max_installments: int}
     */
    public static function cardInstallmentSessionOptions(
        array $checkoutConfig,
        float $amountBrl,
        bool $isSubscription,
        string $method
    ): array {
        if ($method !== 'card') {
            return ['allow_card_installments' => false, 'card_max_installments' => 1];
        }

        $raw = $checkoutConfig['card_installments'] ?? [];
        $resolved = PlatformCardInstallments::forProductConfig(
            is_array($raw) ? $raw : [],
            $isSubscription
        );
        $max = CardInstallments::maxAllowedForAmount($amountBrl, $resolved['max']);
        if (! $resolved['enabled'] || $max < 2) {
            return ['allow_card_installments' => false, 'card_max_installments' => 1];
        }

        return [
            'allow_card_installments' => true,
            'card_max_installments' => $max,
        ];
    }

    /**
     * @return array{token: string, checkout_session_id: string, raw: array<string, mixed>}
     */
    public function createCheckoutSession(Order $order, string $wallet, array $credentials): array
    {
        $public = trim((string) ($credentials['public_key'] ?? ''));
        $secret = trim((string) ($credentials['secret_key'] ?? ''));
        if ($public === '' || $secret === '') {
            throw new \RuntimeException('CajuPay: credenciais incompletas.');
        }

        $amountCents = (int) round(((float) $order->amount) * 100);
        $wallet = $this->normalizeWallet($wallet);
        $order->loadMissing(['product', 'productOffer', 'subscriptionPlan']);
        $flags = self::cardInstallmentSessionOptions(
            $this->checkoutConfigForOrder($order),
            (float) $order->amount,
            $order->subscription_plan_id !== null,
            $wallet
        );

        $allowedMethods = $wallet === 'card' ? ['card'] : [$wallet, 'card'];
        $consumer = array_filter([
            'email' => trim((string) ($order->email ?? '')),
            'document' => preg_replace('/\D/', '', (string) ($order->cpf ?? '')) ?: null,
        ]);

        return app(CajuPayDriver::class)->createSdkCheckoutSession(
            $credentials,
            $amountCents,
            'Pedido #'.$order->id,
            (string) $order->id,
            $consumer,
            $allowedMethods,
            $wallet,
            array_merge($flags, [
                'locale' => CajuPayBrowserSdk::localeFromCheckout('pt_BR'),
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutConfigForOrder(Order $order): array
    {
        $defaults = Product::defaultCheckoutConfig();
        $plan = $order->subscriptionPlan;
        if ($plan && is_array($plan->checkout_config) && $plan->checkout_config !== []) {
            return array_replace_recursive($defaults, $plan->checkout_config);
        }
        $offer = $order->productOffer;
        if ($offer && is_array($offer->checkout_config) && $offer->checkout_config !== []) {
            return array_replace_recursive($defaults, $offer->checkout_config);
        }
        $product = $order->product;
        if ($product && is_array($product->checkout_config) && $product->checkout_config !== []) {
            return array_replace_recursive($defaults, $product->checkout_config);
        }

        return $defaults;
    }

    /**
     * GET público — sem secret. Retorna status normalizado ou null.
     */
    public function getPublicSessionStatus(string $publicToken, ?array $credentials = null): ?string
    {
        $publicToken = trim($publicToken);
        if ($publicToken === '') {
            return null;
        }

        $base = $this->baseUrl($credentials ?? []);

        $response = Http::acceptJson()
            ->timeout(20)
            ->withOptions(['connect_timeout' => 10])
            ->baseUrl($base)
            ->get('/api/sdk/public/checkout/sessions/'.$publicToken);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }

        return $this->mapPublicSessionStatus($data);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function baseUrl(array $credentials): string
    {
        $override = isset($credentials['base_url']) ? trim((string) $credentials['base_url']) : '';
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');
    }

    private function normalizeWallet(string $wallet): string
    {
        $w = strtolower(trim($wallet));

        return in_array($w, ['card', 'apple_pay', 'google_pay'], true) ? $w : 'card';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapPublicSessionStatus(array $data): ?string
    {
        $status = $data['payment_status'] ?? null;
        if (! is_string($status) || trim($status) === '') {
            foreach (['payment', 'latest_payment', 'charge', 'latest_charge'] as $nest) {
                $obj = $data[$nest] ?? null;
                if (! is_array($obj)) {
                    continue;
                }
                $nested = $obj['payment_status'] ?? $obj['status'] ?? $obj['state'] ?? null;
                if (is_string($nested) && trim($nested) !== '') {
                    $status = $nested;
                    break;
                }
            }
        }
        if (! is_string($status) || trim($status) === '') {
            $status = $data['status'] ?? $data['state'] ?? null;
        }
        if (! is_string($status) || trim($status) === '') {
            return 'pending';
        }
        $s = strtolower(trim($status));
        if (in_array($s, ['paid', 'completed', 'succeeded', 'success', 'confirmed', 'approved', 'settled'], true)) {
            return 'paid';
        }
        if (in_array($s, ['failed', 'canceled', 'cancelled', 'rejected', 'declined', 'expired', 'refunded'], true)) {
            return 'cancelled';
        }
        // "active"/"open" = sessão aberta; liquidação vem em payment_status
        if (in_array($s, ['pending', 'processing', 'open', 'active', 'requires_action', 'awaiting_payment'], true)) {
            return 'pending';
        }

        return 'pending';
    }

    public static function resolveCredentialsForOrder(Order $order): ?array
    {
        return app(CajuPayAccountResolver::class)->credentialsForOrder($order);
    }
}
