<?php

namespace App\Gateways\Reven;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reven Payments (app.revenpayments.com.br) — PIX cash-in.
 *
 * Autenticação: header X-Api-Key + assinatura anti-replay (§5 da doc de
 * integração): X-Signature = HMAC-SHA256(api_key, "METHOD\n/api<path>\n<body>\n<ts_ms>\n<nonce>")
 * em hex, com X-Timestamp em milissegundos (±5min) e X-Nonce único. O path
 * do canônico leva o prefixo /api e NÃO inclui a query string; body = "" em GET.
 * Workspaces sem requireSignature ignoram os headers extras.
 *
 * Valores em REAIS (float). A API de seller não expõe consulta por id: o
 * status é resolvido pela listagem GET /gateway/transactions (match por id).
 *
 * @see https://app.revenpayments.com.br/api/docs
 */
class RevenDriver implements GatewayDriver
{
    private const BASE_URL = 'https://app.revenpayments.com.br/api';

    private const STATUS_LOOKUP_MAX_PAGES = 5;

    public function testConnection(array $credentials): bool
    {
        try {
            $response = $this->send($credentials, 'GET', '/gateway/transactions', ['page' => 1, 'limit' => 1], null, 10, 5);
        } catch (\Throwable $e) {
            Log::debug('RevenDriver testConnection error', ['message' => $e->getMessage()]);

            return false;
        }

        return $response->successful();
    }

    /**
     * @param  array{name?: string, document?: string, email?: string, phone?: string}  $consumer
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
        if ($amount <= 0) {
            throw new \RuntimeException('Reven: valor da cobrança inválido.');
        }

        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));
        $customer = [
            'name' => $this->sanitizeName((string) ($consumer['name'] ?? '')),
            'email' => $this->sanitizeEmail((string) ($consumer['email'] ?? '')) ?: 'cliente@nao-informado.com.br',
            'doc' => $document,
            'docType' => strlen($document) === 14 ? 'cnpj' : 'cpf',
        ];
        $phone = preg_replace('/\D/', '', (string) ($consumer['phone'] ?? ''));
        if (is_string($phone) && strlen($phone) >= 10 && strlen($phone) <= 13) {
            $customer['phone'] = $phone;
        }

        $body = [
            'product' => [
                'name' => $this->sanitizeName((string) ($options['description'] ?? ('Pedido '.$externalId))),
                'value' => round($amount, 2),
            ],
            'customer' => $customer,
            'metadata' => [
                'orderId' => $externalId,
            ],
            'expiracaoSegundos' => 86400,
        ];

        $postback = $this->validPostbackUrl($postbackUrl);
        if ($postback !== null) {
            $body['callbackUrl'] = $postback;
        } else {
            Log::warning('RevenDriver: callbackUrl omitido (exige HTTPS público)', [
                'external_id' => $externalId,
            ]);
        }

        try {
            $response = $this->send($credentials, 'POST', '/gateway/charges', [], $body);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Reven: falha de comunicação ao gerar cobrança PIX.', 0, $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('Reven: '.$this->errorMessage($response->json(), 'Erro ao gerar cobrança PIX.'));
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $transactionId = trim((string) ($data['transactionId'] ?? $data['id'] ?? ''));
        if ($transactionId === '') {
            throw new \RuntimeException('Reven: resposta sem transactionId.');
        }

        $pixCode = trim((string) ($data['pixCode'] ?? ''));

        return [
            'transaction_id' => $transactionId,
            'qrcode' => null,
            'copy_paste' => $pixCode !== '' ? $pixCode : null,
            'raw' => $payload,
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $row = $this->findTransactionRow($transactionId, $credentials);
        if ($row === null) {
            return null;
        }

        return $this->mapStatus((string) ($row['status'] ?? ''));
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('Reven: pagamento com cartão não suportado nesta integração.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('Reven: boleto não suportado nesta integração.');
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    private function findTransactionRow(string $transactionId, array $credentials): ?array
    {
        for ($page = 1; $page <= self::STATUS_LOOKUP_MAX_PAGES; $page++) {
            try {
                $response = $this->send($credentials, 'GET', '/gateway/transactions', ['page' => $page, 'limit' => 100], null, 12, 5);
            } catch (\Throwable $e) {
                Log::warning('RevenDriver findTransactionRow error', [
                    'transaction_id' => $transactionId,
                    'page' => $page,
                    'message' => $e->getMessage(),
                ]);

                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];
            $rows = $this->collectRows($payload);
            if ($rows === []) {
                return null;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? $row['transactionId'] ?? ''));
                if ($id !== '' && $id === $transactionId) {
                    return $row;
                }
            }

            if (count($rows) < 100) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function collectRows(array $payload): array
    {
        $data = $payload['data'] ?? $payload;
        if (is_array($data) && array_is_list($data)) {
            /** @var list<array<string, mixed>> $data */
            return $data;
        }
        if (is_array($data)) {
            foreach (['transactions', 'items', 'results', 'rows', 'list', 'data'] as $key) {
                $list = $data[$key] ?? null;
                if (is_array($list) && array_is_list($list)) {
                    /** @var list<array<string, mixed>> $list */
                    return $list;
                }
            }
        }

        return [];
    }

    private function mapStatus(string $status): ?string
    {
        return match (strtoupper(trim($status))) {
            'PAID' => 'paid',
            'PENDING' => 'pending',
            // MED = pago e depois contestado/revertido — nunca liberar como pago.
            'FAILED', 'EXPIRED', 'MED' => 'cancelled',
            default => null,
        };
    }

    /**
     * Envia a requisição assinada (§5): o canônico usa o path com prefixo /api,
     * SEM query string, e o body cru exatamente como enviado ("" em GET).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, int|string>  $query
     * @param  array<string, mixed>|null  $json
     */
    private function send(
        array $credentials,
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        int $timeout = 20,
        int $connectTimeout = 8
    ): Response {
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Reven: configure a API Key da adquirente.');
        }

        $rawBody = $json === null ? '' : (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) (int) round(microtime(true) * 1000);
        $nonce = (string) Str::uuid();
        $canonical = strtoupper($method)."\n".'/api'.$path."\n".$rawBody."\n".$timestamp."\n".$nonce;

        $pending = Http::acceptJson()
            ->timeout($timeout)
            ->withOptions(['connect_timeout' => $connectTimeout])
            ->withHeaders([
                'X-Api-Key' => $apiKey,
                'X-Signature' => hash_hmac('sha256', $canonical, $apiKey),
                'X-Timestamp' => $timestamp,
                'X-Nonce' => $nonce,
            ]);

        if ($rawBody !== '') {
            $pending = $pending->withBody($rawBody, 'application/json');
        }

        $options = $query !== [] ? ['query' => $query] : [];

        $response = $pending->send(strtoupper($method), rtrim(self::BASE_URL, '/').$path, $options);
        if (! $response instanceof Response) {
            throw new \RuntimeException('Reven: resposta inesperada do cliente HTTP.');
        }

        return $response;
    }

    private function errorMessage(mixed $json, string $fallback): string
    {
        if (! is_array($json)) {
            return $fallback;
        }
        foreach (['error', 'message'] as $key) {
            $value = $json[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_array($value)) {
                $inner = $value['message'] ?? null;
                if (is_string($inner) && trim($inner) !== '') {
                    return trim($inner);
                }
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
