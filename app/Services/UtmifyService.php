<?php

namespace App\Services;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Support\BrazilianDocuments;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UtmifyService
{
    private const ENDPOINT = 'https://api.utmify.com.br/api-credentials/orders';

    /**
     * Build payload and send order to UTMIFY API.
     *
     * @param  array{approved_at?: string|null, refunded_at?: string|null}  $options
     */
    public function sendOrder(
        Order $order,
        string $utmifyStatus,
        string $apiKey,
        array $options = []
    ): void {
        $body = $this->buildPayload($order, $utmifyStatus, $options);

        if (config('utmify.debug')) {
            Log::debug('Utmify API request', [
                'order_id' => $order->id,
                'status' => $utmifyStatus,
                'trackingParameters' => $body['trackingParameters'] ?? [],
            ]);
        }

        $this->post($apiKey, $body);
    }

    /**
     * Send a test order to UTMIFY API.
     */
    public function sendTest(string $apiKey): void
    {
        $this->post($apiKey, $this->buildTestPayload());
    }

    public function resolveCheckoutSessionForOrder(Order $order): ?CheckoutSession
    {
        $session = CheckoutSession::query()->where('order_id', $order->id)->first();
        if ($session) {
            return $session;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $token = isset($meta['checkout_session_token']) && is_string($meta['checkout_session_token'])
            ? trim($meta['checkout_session_token'])
            : '';

        if ($token !== '') {
            $session = CheckoutSession::query()->where('session_token', $token)->first();
            if ($session) {
                return $session;
            }
        }

        if ($order->product_id && $order->tenant_id) {
            return CheckoutSession::query()
                ->where('product_id', $order->product_id)
                ->where('tenant_id', $order->tenant_id)
                ->whereNotNull('utm_source')
                ->where('utm_source', '!=', '')
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTestPayload(): array
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $platform = config('getfy.app_name', 'Getfy');

        return [
            'orderId' => 'test-'.Str::uuid()->toString(),
            'platform' => $platform,
            'paymentMethod' => 'pix',
            'status' => 'paid',
            'isTest' => true,
            'createdAt' => $now,
            'approvedDate' => $now,
            'refundedAt' => null,
            'customer' => [
                'name' => 'Cliente Exemplo',
                'email' => 'exemplo@email.com',
            'phone' => '11999999999',
            'document' => '11144477735',
            'country' => 'BR',
            'ip' => null,
            ],
            'products' => [
                [
                    'id' => 'test-product',
                    'name' => 'Produto de teste',
                    'planId' => null,
                    'planName' => null,
                    'quantity' => 1,
                    'priceInCents' => 1000,
                ],
            ],
            'trackingParameters' => $this->buildTrackingParameters(null, [
                'utm_source' => 'test',
                'utm_medium' => 'test',
                'utm_campaign' => 'test',
            ]),
            'commission' => [
                'totalPriceInCents' => 1000,
                'gatewayFeeInCents' => 0,
                'userCommissionInCents' => 1000,
            ],
        ];
    }

    /**
     * @param  array{approved_at?: string|null, refunded_at?: string|null, is_test?: bool}  $options
     * @return array<string, mixed>
     */
    public function buildPayload(Order $order, string $utmifyStatus, array $options = []): array
    {
        $order->loadMissing(['user', 'orderItems.product', 'orderItems.productOffer', 'orderItems.subscriptionPlan']);

        $session = $this->resolveCheckoutSessionForOrder($order);
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $orderId = $this->resolveExternalOrderId($order);
        $paymentMethod = $this->mapPaymentMethod($order);
        $createdAt = $order->created_at->utc()->format('Y-m-d H:i:s');
        $approvedDate = $options['approved_at'] ?? ($utmifyStatus === 'paid' ? $order->updated_at->utc()->format('Y-m-d H:i:s') : null);
        $refundedAt = $options['refunded_at'] ?? null;

        $customer = $this->buildCustomer($order, $session);

        $products = [];
        foreach ($order->orderItems as $item) {
            $product = $item->product;
            $planId = $item->product_offer_id ?? $item->subscription_plan_id;
            $planName = null;
            if ($item->productOffer) {
                $planName = $item->productOffer->name;
            } elseif ($item->subscriptionPlan) {
                $planName = $item->subscriptionPlan->name;
            }
            $products[] = [
                'id' => (string) ($product?->id ?? $item->product_id ?? $item->id),
                'name' => $product?->name ?? 'Produto',
                'planId' => $planId ? (string) $planId : null,
                'planName' => $planName,
                'quantity' => 1,
                'priceInCents' => (int) round((float) $item->amount * 100),
            ];
        }

        if (empty($products)) {
            $mainProduct = $order->product;
            $products[] = [
                'id' => (string) ($mainProduct?->id ?? $order->product_id),
                'name' => $mainProduct?->name ?? 'Produto',
                'planId' => null,
                'planName' => null,
                'quantity' => 1,
                'priceInCents' => (int) round((float) $order->amount * 100),
            ];
        }

        $trackingParameters = $this->buildTrackingParameters($session, $meta);

        $totalCents = (int) round((float) $order->amount * 100);
        $commission = [
            'totalPriceInCents' => $totalCents,
            'gatewayFeeInCents' => 0,
            'userCommissionInCents' => $totalCents,
        ];

        $body = [
            'orderId' => $orderId,
            'platform' => config('getfy.app_name', 'Getfy'),
            'paymentMethod' => $paymentMethod,
            'status' => $utmifyStatus,
            'createdAt' => $createdAt,
            'approvedDate' => $approvedDate,
            'refundedAt' => $refundedAt,
            'customer' => $customer,
            'products' => $products,
            'trackingParameters' => $trackingParameters,
            'commission' => $commission,
        ];

        if (! empty($options['is_test'])) {
            $body['isTest'] = true;
        }

        return $body;
    }

    /**
     * UTMify exige todas as 7 chaves de tracking sempre presentes (string ou null).
     * Prioridade: order.metadata > CheckoutSession.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, string|null>
     */
    public function buildTrackingParameters(?CheckoutSession $session, array $meta): array
    {
        $trackingParameters = [];

        foreach (CheckoutSession::TRACKING_FIELD_KEYS as $key) {
            $trackingParameters[$key] = $this->normalizeTrackingValue($meta[$key] ?? $session?->{$key} ?? null);
        }

        return $trackingParameters;
    }

    /**
     * A API rejeita o pedido inteiro (SCHEMA_VALIDATION_FAILED) se a chave
     * faltar, vier como número/array, ou passar do tamanho aceito.
     * O contrato é string ou null, nas 7 chaves.
     */
    private function normalizeTrackingValue(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $trimmed = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $trimmed) ?? $trimmed;

        return CheckoutSession::truncateTrackingValue($trimmed, 255);
    }

    /**
     * POST to UTMIFY API. Throws on failure.
     */
    public function post(string $apiKey, array $body): \Illuminate\Http\Client\Response
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('UTMIFY payload inválido: '.json_last_error_msg());
        }

        $response = Http::timeout((int) config('utmify.http_timeout', 15))
            ->withHeaders([
                'x-api-token' => $apiKey,
                'Accept' => 'application/json',
            ])
            ->withBody($json, 'application/json')
            ->post(self::ENDPOINT);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'UTMIFY API error: '.$response->status().' '.$response->body()
            );
        }

        return $response;
    }

    /**
     * Id estável do pedido na plataforma. A UTMify atualiza o mesmo orderId
     * entre waiting_payment, paid e refunded. gateway_id muda (CajuPay, Mercado Pago).
     */
    public function resolveExternalOrderId(Order $order): string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $stored = isset($meta['utmify_order_id']) && is_string($meta['utmify_order_id'])
            ? trim($meta['utmify_order_id'])
            : '';
        if ($stored !== '') {
            return $stored;
        }

        return (string) $order->id;
    }

    /**
     * UTMs e token da sessão do pedido de origem (upsell/downsell).
     * Não copia flags de envio (utmify_paid_sent_at), senão o novo pedido é ignorado.
     *
     * @return array<string, string>
     */
    public function trackingMetadataFromOrder(Order $order): array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $out = [];

        $token = isset($meta['checkout_session_token']) && is_string($meta['checkout_session_token'])
            ? trim($meta['checkout_session_token'])
            : '';
        if ($token !== '') {
            $out['checkout_session_token'] = $token;
        }

        foreach (CheckoutSession::TRACKING_FIELD_KEYS as $key) {
            $raw = $meta[$key] ?? null;
            if (is_string($raw) && trim($raw) !== '') {
                $out[$key] = trim($raw);
            }
        }

        return $out;
    }

    /**
     * @return array{name: string, email: string, phone: string|null, document: string|null, country: string, ip: string|null}
     */
    private function buildCustomer(Order $order, ?CheckoutSession $session): array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $name = $this->firstNonEmpty([
            $session?->name,
            $order->user?->name,
            $meta['consumer_name'] ?? null,
        ]) ?? 'Cliente';

        $email = $this->firstNonEmpty([
            $order->email,
            $session?->email,
            $order->user?->email,
        ]) ?? ('pedido'.$order->id.'@checkout.local');

        $phoneDigits = BrazilianDocuments::digits($this->firstNonEmpty([
            $order->phone,
            $session?->phone,
        ]));
        $phone = strlen($phoneDigits) >= 10 && strlen($phoneDigits) <= 13 ? $phoneDigits : null;

        $documentDigits = BrazilianDocuments::digits($this->firstNonEmpty([
            $order->cpf,
            $session?->cpf,
        ]));
        $document = (BrazilianDocuments::isValidCpf($documentDigits) || BrazilianDocuments::isValidCnpj($documentDigits))
            ? $documentDigits
            : null;

        $ipRaw = $this->firstNonEmpty([
            $order->customer_ip,
            $session?->customer_ip,
        ]);
        $ip = is_string($ipRaw) && filter_var($ipRaw, FILTER_VALIDATE_IP) ? $ipRaw : null;

        return [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'document' => $document,
            'country' => 'BR',
            'ip' => $ip,
        ];
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }
            $trimmed = trim((string) $candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function mapPaymentMethod(Order $order): string
    {
        $key = $order->resolveCheckoutPaymentMethodKey();
        if ($key !== null) {
            return match ($key) {
                'pix', 'pix_auto' => 'pix',
                'boleto' => 'boleto',
                'paypal' => 'paypal',
                'card', 'apple_pay', 'google_pay' => 'credit_card',
                default => $this->mapPaymentMethodFromGateway($order->gateway),
            };
        }

        return $this->mapPaymentMethodFromGateway($order->gateway);
    }

    private function mapPaymentMethodFromGateway(?string $gateway): string
    {
        if (! $gateway) {
            return 'pix';
        }
        $g = strtolower($gateway);
        if (str_contains($g, 'pix')) {
            return 'pix';
        }
        if (str_contains($g, 'boleto') || str_contains($g, 'ticket')) {
            return 'boleto';
        }
        if (str_contains($g, 'card') || str_contains($g, 'credit') || str_contains($g, 'cartao')) {
            return 'credit_card';
        }

        return 'pix';
    }
}
