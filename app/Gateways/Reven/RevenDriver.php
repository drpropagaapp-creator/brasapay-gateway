<?php

namespace App\Gateways\Reven;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reven Payments (app.revenpayments.com.br) — PIX cash-in.
 *
 * Autenticação: header X-Api-Key. Valores em REAIS (float).
 * A API de seller não expõe consulta por id: o status é resolvido
 * pela listagem GET /gateway/transactions (match por id).
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
            $response = $this->http($credentials)
                ->timeout(10)
                ->withOptions(['connect_timeout' => 5])
                ->get($this->url('/gateway/transactions'), ['page' => 1, 'limit' => 1]);
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
            $response = $this->http($credentials)->post($this->url('/gateway/charges'), $body);
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
                $response = $this->http($credentials)
                    ->timeout(12)
                    ->withOptions(['connect_timeout' => 5])
                    ->get($this->url('/gateway/transactions'), ['page' => $page, 'limit' => 100]);
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
            'FAILED', 'EXPIRED' => 'cancelled',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function http(array $credentials): PendingRequest
    {
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Reven: configure a API Key da adquirente.');
        }

        return Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->withOptions(['connect_timeout' => 8])
            ->withHeaders(['X-Api-Key' => $apiKey]);
    }

    private function url(string $path): string
    {
        return rtrim(self::BASE_URL, '/').$path;
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
