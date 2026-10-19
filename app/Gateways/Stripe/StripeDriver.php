<?php

namespace App\Gateways\Stripe;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Stripe driver – PIX (BRL) e cartão.
 * PIX: https://docs.stripe.com/payments/pix/accept-a-payment
 * Cartão: https://docs.stripe.com/api
 * Parcelamento não utilizado neste gateway; cartão sempre à vista (1x).
 */
class StripeDriver implements GatewayDriver
{
    /** Zero-decimal currencies (amount = integer units, no * 100). */
    private const ZERO_DECIMAL_CURRENCIES = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf',
        'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    public function testConnection(array $credentials): bool
    {
        $secret = trim($credentials['secret_key'] ?? '');
        if ($secret === '') {
            return false;
        }
        try {
            $stripe = new StripeClient($secret);
            $stripe->balance->retrieve();
            return true;
        } catch (\Throwable $e) {
            Log::debug('StripeDriver testConnection failed', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        $secret = trim((string) ($credentials['secret_key'] ?? ''));
        if ($secret === '') {
            throw new \RuntimeException('Stripe: chave secreta não configurada.');
        }

        $amountCents = $this->amountToSmallestUnit($amount, 'brl');
        if ($amountCents < 50) {
            throw new \RuntimeException('Stripe: o valor mínimo do PIX é R$ 0,50.');
        }

        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?? '';
        if (strlen($document) !== 11) {
            throw new \RuntimeException('Informe um CPF válido para pagar com PIX na Stripe.');
        }

        $name = trim((string) ($consumer['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Informe o nome para pagar com PIX na Stripe.');
        }

        $email = trim((string) ($consumer['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Informe um e-mail válido para pagar com PIX na Stripe.');
        }

        $stripe = new StripeClient($secret);
        try {
            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => $amountCents,
                'currency' => 'brl',
                'payment_method_types' => ['pix'],
                'confirm' => true,
                'payment_method_data' => [
                    'type' => 'pix',
                    'billing_details' => [
                        'name' => $name,
                        'email' => $email,
                        'tax_id' => $this->formatCpf($document),
                    ],
                ],
                'payment_method_options' => [
                    'pix' => [
                        'expires_after_seconds' => 900,
                    ],
                ],
                'metadata' => ['order_id' => $externalId],
            ], [
                'idempotency_key' => 'getfy-stripe-pix-'.$externalId,
            ]);
        } catch (ApiErrorException $e) {
            Log::warning('StripeDriver createPixPayment failed', [
                'order_id' => $externalId,
                'code' => $e->getStripeCode(),
                'message' => $e->getMessage(),
            ]);
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'tax') || str_contains($message, 'cpf')) {
                throw new \RuntimeException('CPF não aceito pela Stripe. Confira o documento e tente novamente.');
            }
            if (str_contains($message, 'pix')) {
                throw new \RuntimeException('A Stripe recusou o PIX. Ative o Pix no painel da Stripe e tente novamente.');
            }
            throw new \RuntimeException('Não foi possível gerar o PIX na Stripe. Tente novamente.');
        } catch (\Throwable $e) {
            Log::warning('StripeDriver createPixPayment error', [
                'order_id' => $externalId,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Não foi possível gerar o PIX na Stripe. Tente novamente.');
        }

        return $this->pixChargeFromPaymentIntent($paymentIntent);
    }

    /**
     * @param  object  $paymentIntent
     * @return array{transaction_id: string, qrcode?: string, copy_paste?: string, status?: string}
     */
    public function pixChargeFromPaymentIntent(object $paymentIntent): array
    {
        $payload = method_exists($paymentIntent, 'toArray') ? $paymentIntent->toArray() : [];
        $pix = is_array($payload['next_action']['pix_display_qr_code'] ?? null)
            ? $payload['next_action']['pix_display_qr_code']
            : [];
        $copyPaste = trim((string) ($pix['data'] ?? ''));
        $qrcode = trim((string) ($pix['image_url_png'] ?? $pix['image_url_svg'] ?? ''));
        $transactionId = trim((string) ($payload['id'] ?? ''));
        if ($transactionId === '' || ($copyPaste === '' && $qrcode === '')) {
            throw new \RuntimeException('A Stripe não devolveu o código PIX. Tente novamente.');
        }

        return [
            'transaction_id' => $transactionId,
            'copy_paste' => $copyPaste,
            'qrcode' => $qrcode,
            'status' => 'pending',
        ];
    }

    private function formatCpf(string $digits): string
    {
        return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('Stripe não suporta boleto neste gateway. Use cartão de crédito.');
    }

    /**
     * Cria e confirma PaymentIntent com cartão (payment_method = Stripe PaymentMethod id).
     *
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array{payment_token: string, card_mask?: string, currency?: string}  $card
     * @return array{transaction_id: string, status?: string, client_secret?: string}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $secret = trim($credentials['secret_key'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException('Stripe: chave secreta não configurada.');
        }

        $paymentMethodId = trim($card['payment_token'] ?? '');
        if ($paymentMethodId === '' || ! str_starts_with($paymentMethodId, 'pm_')) {
            throw new \RuntimeException('Stripe: método de pagamento inválido. Preencha os dados do cartão novamente.');
        }

        $currency = strtolower(trim($card['currency'] ?? 'usd'));
        if (strlen($currency) !== 3) {
            $currency = 'usd';
        }

        $amountInSmallestUnit = $this->amountToSmallestUnit($amount, $currency);

        if ($amountInSmallestUnit < 50 && $currency === 'usd') {
            throw new \RuntimeException('Stripe: valor mínimo é 0.50 USD ou equivalente.');
        }

        $returnUrl = trim($card['return_url'] ?? '');
        if ($returnUrl === '') {
            $returnUrl = null;
        }

        $stripe = new StripeClient($secret);
        $params = [
            'amount' => $amountInSmallestUnit,
            'currency' => $currency,
            'payment_method' => $paymentMethodId,
            'confirm' => true,
            'metadata' => ['order_id' => $externalId],
            'receipt_email' => $consumer['email'] ?? null,
        ];
        if ($returnUrl !== null) {
            $params['return_url'] = $returnUrl;
        }

        try {
            $paymentIntent = $stripe->paymentIntents->create($params);

            $status = $paymentIntent->status ?? null;
            $transactionId = $paymentIntent->id ?? '';

            if ($status === 'succeeded') {
                return [
                    'transaction_id' => $transactionId,
                    'status' => 'paid',
                ];
            }

            if ($status === 'requires_action' && ! empty($paymentIntent->client_secret)) {
                return [
                    'transaction_id' => $transactionId,
                    'status' => 'requires_action',
                    'client_secret' => $paymentIntent->client_secret,
                ];
            }

            if ($status === 'requires_payment_method') {
                throw new \RuntimeException('Pagamento recusado. Verifique os dados do cartão ou tente outro cartão.');
            }

            return [
                'transaction_id' => $transactionId,
                'status' => $status ?? 'pending',
            ];
        } catch (ApiErrorException $e) {
            $code = $e->getStripeCode() ?? '';
            $message = $e->getMessage();
            Log::warning('StripeDriver createCardPayment failed', [
                'order_id' => $externalId,
                'code' => $code,
                'message' => $message,
            ]);
            if (str_contains(strtolower($message), 'card_declined') || $code === 'card_declined') {
                throw new \RuntimeException('Cartão recusado. Verifique os dados ou tente outro cartão.');
            }
            if (str_contains(strtolower($message), 'incorrect_cvc') || $code === 'incorrect_cvc') {
                throw new \RuntimeException('Código de segurança (CVV) incorreto.');
            }
            if (str_contains(strtolower($message), 'expired_card') || $code === 'expired_card') {
                throw new \RuntimeException('Cartão expirado.');
            }
            throw new \RuntimeException('Não foi possível processar o pagamento. Tente novamente.');
        } catch (\Throwable $e) {
            Log::warning('StripeDriver createCardPayment error', [
                'order_id' => $externalId,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Não foi possível processar o pagamento. Tente novamente.');
        }
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $secret = trim($credentials['secret_key'] ?? '');
        if ($secret === '') {
            return null;
        }
        try {
            $stripe = new StripeClient($secret);
            $pi = $stripe->paymentIntents->retrieve($transactionId);
            $status = $pi->status ?? null;
            if ($status === 'succeeded') {
                return 'paid';
            }
            if (in_array($status, ['requires_action', 'requires_confirmation', 'requires_payment_method', 'processing'], true)) {
                return 'pending';
            }
            if ($status === 'canceled') {
                return 'cancelled';
            }
            return 'pending';
        } catch (\Throwable $e) {
            Log::debug('StripeDriver getTransactionStatus failed', ['tx' => $transactionId, 'message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Saldo da conta (GET /v1/balance). Auth HTTP Basic com a secret key, como na documentação da Stripe.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function fetchAccountBalance(array $credentials): array
    {
        $secret = trim((string) ($credentials['secret_key'] ?? ''));
        if ($secret === '') {
            throw new \RuntimeException('Stripe: chave secreta não configurada.');
        }

        try {
            $balance = (new StripeClient($secret))->balance->retrieve();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe: falha ao consultar saldo: '.$e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Stripe: falha ao consultar saldo.', 0, $e);
        }

        $payload = $balance->toArray();

        return is_array($payload) ? $payload : [];
    }

    public static function fromSmallestUnit(int $amount, string $currency): float
    {
        $currency = strtolower($currency);
        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return round((float) $amount, 2);
        }

        return round($amount / 100, 2);
    }

    private function amountToSmallestUnit(float $amount, string $currency): int
    {
        $currency = strtolower($currency);
        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }
}
