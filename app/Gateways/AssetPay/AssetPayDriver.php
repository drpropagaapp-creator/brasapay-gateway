<?php

namespace App\Gateways\AssetPay;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AssetPay (assetpay.com.br) — PIX cash-in e cash-out.
 *
 * Autenticação: Basic base64(secret_key:public_key).
 * Cash In: valores em CENTAVOS. Cash Out: valores em REAIS.
 *
 * @see https://assetpay.apidog.io/
 */
class AssetPayDriver implements GatewayDriver
{
    private const BASE_URL = 'https://api.assetpay.com.br/api/v1';

    public function testConnection(array $credentials): bool
    {
        try {
            $response = $this->http($credentials)
                ->timeout(10)
                ->withOptions(['connect_timeout' => 5])
                ->get($this->url('/transactions'), ['page' => 1, 'limit' => 1]);
        } catch (\Throwable $e) {
            Log::debug('AssetPayDriver testConnection error', ['message' => $e->getMessage()]);

            return false;
        }

        return $response->successful();
    }

    /**
     * @param  array{name?: string, document?: string, email?: string, phone?: string, ip?: string}  $consumer
     * @return array{transaction_id: string, qrcode?: string|null, copy_paste?: string|null, raw?: array}
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        $amountCents = (int) round($amount * 100);
        if ($amountCents < 1) {
            throw new \RuntimeException('AssetPay: valor da cobrança inválido.');
        }

        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));
        $name = $this->sanitizeName((string) ($consumer['name'] ?? ''));
        $email = $this->sanitizeEmail((string) ($consumer['email'] ?? ''));

        $customer = [
            'name' => $name,
            'email' => $email !== '' ? $email : 'cliente@nao-informado.com.br',
            'document' => [
                'type' => strlen($document) === 14 ? 'CNPJ' : 'CPF',
                'number' => $document,
            ],
        ];
        $phone = preg_replace('/\D/', '', (string) ($consumer['phone'] ?? ''));
        if (is_string($phone) && strlen($phone) >= 10) {
            $customer['phone'] = $phone;
        }
        $ip = trim((string) ($consumer['ip'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $customer['ip'] = $ip;
        }

        $body = [
            'amount' => $amountCents,
            'paymentMethod' => 'PIX',
            'externalRef' => $externalId,
            'customer' => $customer,
            'items' => [
                [
                    'title' => $this->sanitizeName((string) ($options['description'] ?? ('Pedido '.$externalId))),
                    'unitPrice' => $amountCents,
                    'quantity' => 1,
                ],
            ],
            'pix' => [
                'expiresInDays' => 1,
            ],
        ];

        $postback = $this->validPostbackUrl($postbackUrl);
        if ($postback !== null) {
            $body['postbackUrl'] = $postback;
        } else {
            Log::warning('AssetPayDriver: postbackUrl omitido (exige HTTPS público)', [
                'external_ref' => $externalId,
            ]);
        }

        try {
            $response = $this->http($credentials)->post($this->url('/transactions'), $body);
        } catch (\Throwable $e) {
            throw new \RuntimeException('AssetPay: falha de comunicação ao gerar cobrança PIX.', 0, $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('AssetPay: '.$this->errorMessage($response->json(), 'Erro ao gerar cobrança PIX.'));
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $transactionId = trim((string) ($payload['id'] ?? ''));
        if ($transactionId === '') {
            throw new \RuntimeException('AssetPay: resposta sem id da transação.');
        }

        $pix = is_array($payload['pix'] ?? null) ? $payload['pix'] : [];
        $emv = trim((string) ($pix['qrcode'] ?? ''));

        return [
            'transaction_id' => $transactionId,
            'qrcode' => null,
            'copy_paste' => $emv !== '' ? $emv : null,
            'raw' => $payload,
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        try {
            $response = $this->http($credentials)
                ->timeout(12)
                ->withOptions(['connect_timeout' => 5])
                ->get($this->url('/transactions/'.rawurlencode($transactionId)));
        } catch (\Throwable $e) {
            Log::warning('AssetPayDriver getTransactionStatus error', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $status = strtolower(trim((string) $response->json('status', '')));

        return match ($status) {
            // "blocked" = pago com saldo retido por infração (MED) — o pedido foi pago.
            'paid', 'blocked' => 'paid',
            'pending', 'processing' => 'pending',
            'failed', 'cancelled', 'canceled', 'expired' => 'cancelled',
            default => null,
        };
    }

    /**
     * Saque PIX (cash out). Valores em REAIS.
     *
     * @return array{ok: bool, transaction_id?: string|null, error?: string}
     */
    public function createPixCashout(
        array $credentials,
        float $amount,
        string $pixKey,
        string $keyType,
        string $externalId,
        string $postbackUrl,
        string $receiverName = ''
    ): array {
        $key = trim($pixKey);
        $type = $this->normalizePixKeyType($keyType, $key);
        if ($type === 'PHONE') {
            $digits = preg_replace('/\D/', '', $key);
            $key = is_string($digits) && $digits !== '' ? $digits : $key;
        }
        if ($key === '' || $type === '') {
            return ['ok' => false, 'error' => 'Chave PIX de destino inválida.'];
        }

        $body = [
            'amount' => round($amount, 2),
            'pix_key' => $key,
            'pix_type' => $type,
            'client_reference_id' => $externalId,
        ];
        $postback = $this->validPostbackUrl($postbackUrl);
        if ($postback !== null) {
            $body['postbackUrl'] = $postback;
        }

        try {
            $response = $this->http($credentials)->post($this->url('/transactions/withdraw'), $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'AssetPay: '.$e->getMessage()];
        }

        if ($response->status() === 403) {
            return ['ok' => false, 'error' => 'AssetPay: IP não autorizado para saques (cadastre o IP do servidor na whitelist em Dashboard → APIs).'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'AssetPay: '.$this->errorMessage($response->json(), 'Erro ao enviar o saque PIX.')];
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $withdrawId = trim((string) ($data['external_id'] ?? $data['id'] ?? ''));

        return [
            'ok' => true,
            'transaction_id' => $withdrawId !== '' ? $withdrawId : null,
        ];
    }

    /**
     * Status do saque: GET /transactions/withdraw/{id} (ID externo do saque).
     * Retorna 'paid', 'pending', 'failed', 'cancelled' ou null.
     */
    public function getCashoutStatus(string $withdrawId, array $credentials): ?string
    {
        try {
            $response = $this->http($credentials)
                ->timeout(12)
                ->withOptions(['connect_timeout' => 5])
                ->get($this->url('/transactions/withdraw/'.rawurlencode($withdrawId)));
        } catch (\Throwable $e) {
            Log::warning('AssetPayDriver getCashoutStatus error', [
                'withdraw_id' => $withdrawId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $label = strtolower(trim((string) ($data['status_label'] ?? '')));
        if ($label === '' && isset($data['status']) && is_numeric($data['status'])) {
            $label = match ((int) $data['status']) {
                0 => 'completed',
                1 => 'pending',
                2 => 'processing',
                3 => 'failed',
                4 => 'cancelled',
                default => '',
            };
        }

        return match ($label) {
            'completed' => 'paid',
            'pending', 'processing' => 'pending',
            'failed' => 'failed',
            'cancelled', 'canceled' => 'cancelled',
            default => null,
        };
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('AssetPay: pagamento com cartão ainda não habilitado nesta integração.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('AssetPay: boleto ainda não habilitado nesta integração.');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function http(array $credentials): PendingRequest
    {
        $secretKey = trim((string) ($credentials['secret_key'] ?? ''));
        $publicKey = trim((string) ($credentials['public_key'] ?? ''));
        if ($secretKey === '' || $publicKey === '') {
            throw new \RuntimeException('AssetPay: configure Secret Key e Public Key da adquirente.');
        }

        return Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->withOptions(['connect_timeout' => 8])
            ->withBasicAuth($secretKey, $publicKey);
    }

    private function url(string $path): string
    {
        return rtrim(self::BASE_URL, '/').$path;
    }

    private function normalizePixKeyType(string $type, string $key): string
    {
        $t = strtolower(trim($type));
        $mapped = match ($t) {
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'EMAIL',
            'phone', 'telefone' => 'PHONE',
            'random', 'evp', 'aleatoria', 'aleatória' => 'RANDOM',
            default => '',
        };
        if ($mapped !== '') {
            return $mapped;
        }
        if (str_contains($key, '@')) {
            return 'EMAIL';
        }
        $digits = preg_replace('/\D/', '', $key);
        $digits = is_string($digits) ? $digits : '';
        if (strlen($digits) === 11) {
            if (preg_match('/^[1-9]{2}9\d{8}$/', $digits) === 1) {
                return 'PHONE';
            }

            return 'CPF';
        }
        if (strlen($digits) === 14) {
            return 'CNPJ';
        }
        if (strlen($digits) >= 10 && strlen($digits) <= 13) {
            return 'PHONE';
        }

        return 'RANDOM';
    }

    private function errorMessage(mixed $json, string $fallback): string
    {
        if (! is_array($json)) {
            return $fallback;
        }
        foreach (['message', 'error'] as $key) {
            $value = $json[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $fallback;
    }

    private function normalizeDocument(string $document): string
    {
        $digits = preg_replace('/\D/', '', $document);
        $digits = is_string($digits) ? $digits : '';
        if (strlen($digits) === 11 || strlen($digits) === 14) {
            return $digits;
        }

        return $digits !== '' ? $digits : '00000000000';
    }

    private function sanitizeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: '');
        if ($name === '') {
            return 'Cliente';
        }

        return mb_strlen($name) > 100 ? mb_substr($name, 0, 100) : $name;
    }

    private function sanitizeEmail(string $email): string
    {
        $email = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $email) ?: '');
        if ($email === '') {
            return '';
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function validPostbackUrl(string $value): ?string
    {
        $v = trim(str_replace(["\r", "\n", "\t", '`', '"', "'"], '', $value));
        if ($v === '' || ! str_starts_with($v, 'https://')) {
            return null;
        }

        return filter_var($v, FILTER_VALIDATE_URL) ? $v : null;
    }
}
