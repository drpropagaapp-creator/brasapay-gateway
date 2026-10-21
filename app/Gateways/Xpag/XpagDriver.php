<?php

namespace App\Gateways\Xpag;

use App\Gateways\Contracts\GatewayDriver;
use App\Support\BrazilianDocumentDigits;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XpagDriver implements GatewayDriver
{
    public const BASE_URL = 'https://api.xpag.global';

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        try {
            $this->fetchAccountBalance($credentials);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, document?: string, email?: string}  $consumer
     * @param  array<string, mixed>  $options
     * @return array{transaction_id: string, qrcode?: string|null, copy_paste?: string|null, raw?: array, metadata?: array}
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        $this->assertCredentials($credentials);

        $amountBrl = round(max(0.01, $amount), 2);
        $body = [
            'currency' => 'BRL',
            'amount' => $amountBrl,
            'external_id' => mb_substr(trim($externalId), 0, 80),
            'description' => mb_substr(trim((string) ($options['description'] ?? 'Pedido #'.$externalId)), 0, 140),
        ];

        $name = trim((string) ($consumer['name'] ?? ''));
        if ($name !== '') {
            $body['name'] = mb_substr($name, 0, 140);
        }

        $document = BrazilianDocumentDigits::onlyDigits((string) ($consumer['document'] ?? ''));
        if (in_array(strlen($document), [11, 14], true)) {
            $body['document'] = $document;
        }

        $webhook = trim($postbackUrl);
        if (str_starts_with($webhook, 'https://')) {
            $body['webhook_url'] = $webhook;
        }

        try {
            $response = $this->http($credentials)->post($this->url('/cashin'), $body);
        } catch (\Throwable $e) {
            Log::error('XpagDriver createPixPayment connection failed', [
                'external_id' => $externalId,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('XPag: falha de conexão com '.self::BASE_URL.' — '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException('XPag: '.$this->errorMessage($response, 'Erro ao gerar cobrança PIX.'));
        }

        $payload = $this->jsonArray($response);
        if (($payload['ok'] ?? true) === false) {
            throw new RuntimeException('XPag: '.$this->errorMessageFromArray($payload, 'Cobrança PIX recusada.'));
        }

        $transactionId = trim((string) ($payload['transaction_id'] ?? $payload['request_number'] ?? ''));
        if ($transactionId === '') {
            throw new RuntimeException('XPag: resposta sem transaction_id da cobrança.');
        }

        $copyPaste = trim((string) ($payload['copyPaste'] ?? $payload['code'] ?? ''));
        $qrImage = trim((string) ($payload['qr_img'] ?? $payload['qr_url'] ?? ''));

        return [
            'transaction_id' => $transactionId,
            'qrcode' => $qrImage !== '' ? $qrImage : null,
            'copy_paste' => $copyPaste !== '' ? $copyPaste : null,
            'raw' => $payload,
            'metadata' => [
                'xpag_request_number' => trim((string) ($payload['request_number'] ?? '')),
                'xpag_external_id' => $body['external_id'],
            ],
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $payload = $this->consultTransaction($credentials, $transactionId);
        if ($payload === null) {
            return null;
        }

        return $this->mapCashinStatus($payload['status'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, pending?: bool, transaction_id?: string|null, error?: string, raw?: array}
     */
    public function createTransfer(
        array $credentials,
        float $amountBrl,
        string $pixKey,
        string $pixKeyType,
        string $externalId,
        string $name,
        ?string $document,
        string $webhookUrl,
    ): array {
        try {
            $this->assertCredentials($credentials);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $pixKey = trim($pixKey);
        if ($pixKey === '') {
            return ['ok' => false, 'error' => 'XPag: chave PIX de destino ausente.'];
        }

        $type = $this->normalizePixType($pixKeyType, $pixKey);
        $formattedKey = $this->formatPixKey($pixKey, $type);
        $amount = round(max(0.01, $amountBrl), 2);

        $body = [
            'currency' => 'BRL',
            'amount' => $amount,
            'name' => mb_substr(trim($name) !== '' ? trim($name) : 'Favorecido', 0, 140),
            'pix_key' => $formattedKey,
            'pix_type' => $type,
            'external_id' => mb_substr(trim($externalId), 0, 80),
        ];

        $doc = BrazilianDocumentDigits::onlyDigits((string) $document);
        if ($doc === '' && in_array($type, ['CPF', 'CNPJ'], true)) {
            $doc = BrazilianDocumentDigits::onlyDigits($formattedKey);
        }
        if (in_array(strlen($doc), [11, 14], true)) {
            $body['document'] = $doc;
        }

        $webhook = trim($webhookUrl);
        if (str_starts_with($webhook, 'https://')) {
            $body['webhook_url'] = $webhook;
        }

        try {
            $response = $this->http($credentials)
                ->timeout(40)
                ->post($this->url('/cashout'), $body);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'XPag: falha na requisição de saque.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'XPag: '.$this->errorMessage($response, 'Erro ao enviar o saque PIX.')];
        }

        $payload = $this->jsonArray($response);
        if (($payload['ok'] ?? true) === false) {
            return ['ok' => false, 'error' => 'XPag: '.$this->errorMessageFromArray($payload, 'Saque recusado.')];
        }

        $id = trim((string) ($payload['e2e'] ?? $payload['transaction_id'] ?? $externalId));

        return [
            'ok' => true,
            'pending' => true,
            'transaction_id' => $id !== '' ? $id : null,
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getTransferStatus(string $transferId, array $credentials): ?string
    {
        $transferId = trim($transferId);
        if ($transferId === '') {
            return null;
        }

        foreach (['e2e', 'transaction_id', 'external_id'] as $param) {
            try {
                $response = $this->http($credentials)->get($this->url('/consult-cashout'), [
                    $param => $transferId,
                ]);
            } catch (\Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $json = $this->jsonArray($response);
            $mapped = $this->mapCashoutStatus($json['status'] ?? null);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function fetchAccountBalance(array $credentials): array
    {
        $this->assertCredentials($credentials);

        try {
            $response = $this->http($credentials)->get($this->url('/balance'));
        } catch (\Throwable $e) {
            throw new RuntimeException('XPag: falha ao consultar saldo.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('XPag: '.$this->errorMessage($response, 'Falha ao consultar saldo.'));
        }

        $payload = $this->jsonArray($response);
        if (($payload['ok'] ?? true) === false) {
            throw new RuntimeException('XPag: '.$this->errorMessageFromArray($payload, 'Saldo recusado.'));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    /**
     * Consulta estrita por external_id (sem fallback para transaction_id),
     * para vincular pedido a transação a partir de evento não autenticado.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    public function consultTransactionByExternalId(array $credentials, string $externalId): ?array
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return null;
        }

        try {
            $response = $this->http($credentials)->get($this->url('/consult-transaction'), [
                'external_id' => $externalId,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $this->jsonArray($response);
        if (($json['ok'] ?? true) === false) {
            return null;
        }

        return $json;
    }

    public function consultTransaction(array $credentials, string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        foreach (['transaction_id', 'request_number', 'external_id'] as $param) {
            try {
                $response = $this->http($credentials)->get($this->url('/consult-transaction'), [
                    $param => $identifier,
                ]);
            } catch (\Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $json = $this->jsonArray($response);
            if (($json['ok'] ?? true) === false) {
                continue;
            }

            return $json;
        }

        return null;
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new RuntimeException('XPag não suporta pagamento com cartão neste fluxo.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new RuntimeException('XPag não suporta boleto neste fluxo.');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function assertCredentials(array $credentials): void
    {
        if ($this->clientId($credentials) === '' || $this->clientSecret($credentials) === '') {
            throw new RuntimeException('XPag: informe Client ID e Client Secret.');
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function http(array $credentials): PendingRequest
    {
        return Http::timeout(25)
            ->connectTimeout(10)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Client-Id' => $this->clientId($credentials),
                'X-Client-Secret' => $this->clientSecret($credentials),
            ]);
    }

    private function url(string $path): string
    {
        return rtrim(self::BASE_URL, '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function clientId(array $credentials): string
    {
        return trim((string) ($credentials['client_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function clientSecret(array $credentials): string
    {
        return trim((string) ($credentials['client_secret'] ?? ''));
    }

    private function normalizePixType(string $type, string $key): string
    {
        $normalized = strtoupper(trim($type));
        if (in_array($normalized, ['RANDOM', 'EVP'], true) || $normalized === '') {
            $digits = BrazilianDocumentDigits::onlyDigits($key);
            if (strlen($digits) === 11) {
                return 'CPF';
            }
            if (strlen($digits) === 14) {
                return 'CNPJ';
            }
            if (str_contains($key, '@')) {
                return 'EMAIL';
            }
            if ($digits !== '' && (str_starts_with($key, '+') || strlen($digits) >= 10)) {
                return 'PHONE';
            }

            return 'EVP';
        }

        return match (strtolower($type)) {
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'EMAIL',
            'phone', 'telefone' => 'PHONE',
            'evp', 'random', 'aleatoria', 'aleatória' => 'EVP',
            default => $normalized,
        };
    }

    private function formatPixKey(string $key, string $type): string
    {
        if (in_array($type, ['CPF', 'CNPJ'], true)) {
            return BrazilianDocumentDigits::onlyDigits($key);
        }

        if ($type === 'PHONE') {
            $digits = BrazilianDocumentDigits::onlyDigits($key);
            if (str_starts_with(trim($key), '+')) {
                return '+'.$digits;
            }
            if (strlen($digits) === 11) {
                return '+55'.$digits;
            }

            return $digits !== '' ? '+'.$digits : $key;
        }

        return trim($key);
    }

    private function mapCashinStatus(mixed $status): ?string
    {
        $s = strtolower(trim((string) $status));

        return match ($s) {
            'confirmed', 'paid' => 'paid',
            'pending' => 'pending',
            'expired', 'cancelled', 'canceled' => 'cancelled',
            default => $s !== '' ? $s : null,
        };
    }

    private function mapCashoutStatus(mixed $status): ?string
    {
        $s = strtolower(trim((string) $status));

        return match ($s) {
            'completed', 'paid', 'success' => 'paid',
            'pending' => 'pending',
            'failed', 'cancelled', 'canceled' => 'failed',
            default => $s !== '' ? $s : null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonArray(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function errorMessage(Response $response, string $fallback): string
    {
        return $this->errorMessageFromArray($this->jsonArray($response), $fallback);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorMessageFromArray(array $payload, string $fallback): string
    {
        $code = trim((string) ($payload['error_code'] ?? ''));
        $error = trim((string) ($payload['error'] ?? $payload['message'] ?? ''));
        if ($code !== '' && $error !== '') {
            return $code.': '.$error;
        }
        if ($error !== '') {
            return $error;
        }
        if ($code !== '') {
            return $code;
        }

        return $fallback;
    }
}
