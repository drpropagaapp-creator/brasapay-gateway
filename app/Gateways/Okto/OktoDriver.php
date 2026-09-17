<?php

namespace App\Gateways\Okto;

use App\Gateways\Contracts\GatewayDriver;
use App\Support\BrazilianDocumentDigits;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OktoDriver implements GatewayDriver
{
    public const STAGING_BASE = 'https://demo-pix.oktopay.eu';

    public const PRODUCTION_BASE = 'https://pix.oktopay.eu';

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
        $token = $this->accessToken($credentials);
        if ($token === '') {
            throw new RuntimeException('Okto: informe o access token.');
        }

        $document = BrazilianDocumentDigits::onlyDigits((string) ($consumer['document'] ?? ''));
        if (strlen($document) === 14) {
            throw new RuntimeException('Okto PIX aceita apenas CPF do pagador (não CNPJ).');
        }
        if (strlen($document) !== 11) {
            throw new RuntimeException('Okto: CPF do pagador é obrigatório.');
        }

        $name = trim((string) ($consumer['name'] ?? ''));
        if ($name === '') {
            $name = 'Cliente';
        }

        $amountCents = max(1, (int) round($amount * 100));
        $expiration = (int) ($options['expiration'] ?? 3600);
        if ($expiration < 60) {
            $expiration = 3600;
        }
        if ($expiration > 10800) {
            $expiration = 10800;
        }

        $body = [
            'name' => mb_substr($name, 0, 140),
            'taxId' => $document,
            'amount' => $amountCents,
            'expiration' => $expiration,
            'externalId' => mb_substr(trim($externalId), 0, 80),
        ];

        $response = $this->http($credentials)->post($this->url($credentials, '/transactions/deposit'), $body);
        if (! $response->successful()) {
            throw new RuntimeException('Okto: '.$this->errorMessage($response, 'Erro ao gerar cobrança PIX.'));
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $transactionId = trim((string) ($payload['id'] ?? ''));
        if ($transactionId === '') {
            throw new RuntimeException('Okto: resposta sem id da cobrança.');
        }

        $brcode = trim((string) ($payload['brcode'] ?? ''));

        return [
            'transaction_id' => $transactionId,
            'qrcode' => null,
            'copy_paste' => $brcode !== '' ? $brcode : null,
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return null;
        }

        try {
            $response = $this->http($credentials)->post($this->url($credentials, '/transactions/status'), [
                'id' => $transactionId,
                'type' => 'deposit',
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        return $this->mapDepositStatus($json['status'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, document?: string, email?: string}  $consumer
     * @param  array{payment_token: string, card_mask?: string}  $card
     * @return array{transaction_id: string, status?: string}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new RuntimeException('Okto não suporta pagamento com cartão neste fluxo.');
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, document?: string, email?: string}  $consumer
     * @return array{transaction_id: string, amount: float, expire_at: string, barcode: string, pdf_url: string, raw?: array}
     */
    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new RuntimeException('Okto não suporta boleto neste fluxo.');
    }

    /**
     * Saldo em reais (a API Non Betting já devolve float, sem converter centavos).
     *
     * @param  array<string, mixed>  $credentials
     * @return array{availableBalance?: float|int, totalBalance?: float|int, pendingBalance?: float|int}
     */
    public function fetchAccountBalance(array $credentials): array
    {
        $token = $this->accessToken($credentials);
        if ($token === '') {
            throw new RuntimeException('Okto: informe o access token.');
        }

        $response = $this->http($credentials)
            ->timeout(8)
            ->withOptions(['connect_timeout' => 4])
            ->get($this->url($credentials, '/operator/v2/balance'));

        if (! $response->successful()) {
            throw new RuntimeException('Okto: falha ao consultar saldo (HTTP '.$response->status().').');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Okto: resposta de saldo inválida.');
        }

        return $json;
    }

    /**
     * Cash-out Non Betting: só chave PIX (sem bankAccount).
     *
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, pending?: bool, transaction_id?: string|null, error?: string, raw?: array}
     */
    public function createTransfer(
        array $credentials,
        int $amountCents,
        string $pixKey,
        string $pixKeyType,
        string $externalId,
        ?string $ownerTaxId = null,
    ): array {
        $pixKey = trim($pixKey);
        $amountCents = max(1, $amountCents);
        if ($pixKey === '') {
            return ['ok' => false, 'error' => 'Okto: chave PIX de destino ausente.'];
        }

        $type = $this->normalizePixKeyType($pixKeyType, $pixKey);
        if ($type === 'cnpj') {
            return ['ok' => false, 'error' => 'Okto: saque por chave CNPJ não é suportado. Use CPF, e-mail, telefone ou EVP.'];
        }

        $dictKey = $this->formatDictKey($pixKey, $type);
        $body = [
            'dictKey' => $dictKey,
            'externalId' => mb_substr(trim($externalId), 0, 80),
            'amount' => $amountCents,
        ];

        if (in_array($type, ['email', 'phone'], true)) {
            $taxId = BrazilianDocumentDigits::onlyDigits((string) $ownerTaxId);
            if (strlen($taxId) !== 11) {
                return ['ok' => false, 'error' => 'Okto: informe o CPF do titular quando a chave PIX for e-mail ou telefone.'];
            }
            $body['taxId'] = $taxId;
        }

        try {
            $response = $this->http($credentials)
                ->timeout(40)
                ->post($this->url($credentials, '/transactions/withdraw'), $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Okto: falha na requisição de saque.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'Okto: '.$this->errorMessage($response, 'Erro ao enviar o saque PIX.')];
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            $id = trim((string) ($payload['externalId'] ?? $externalId));
        }

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

        foreach ([['id' => $transferId], ['externalId' => $transferId]] as $identity) {
            try {
                $response = $this->http($credentials)->post($this->url($credentials, '/transactions/status'), array_merge($identity, [
                    'type' => 'withdraw',
                ]));
            } catch (\Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $json = $response->json();
            if (! is_array($json)) {
                continue;
            }

            $mapped = $this->mapWithdrawStatus($json['status'] ?? null);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    /**
     * Estorno total do depósito pago.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{success: bool, pending?: bool, message?: string, error_code?: string, raw?: array<string, mixed>}
     */
    public function refundTransaction(array $credentials, string $txId, float $amount, string $externalId): array
    {
        $depositId = trim($txId);
        if ($depositId === '') {
            return [
                'success' => false,
                'message' => 'Okto: ID da cobrança ausente para estorno.',
                'error_code' => 'missing_charge_id',
            ];
        }

        try {
            $response = $this->http($credentials)->put($this->url($credentials, '/transactions/deposit/'.rawurlencode($depositId).'/refund'));
        } catch (\Throwable $e) {
            Log::warning('OktoDriver refundTransaction request failed', [
                'order_id' => $externalId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return [
                'success' => false,
                'message' => 'Okto: falha de comunicação ao solicitar estorno.',
                'error_code' => 'communication_failure',
            ];
        }

        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => $this->errorMessage($response, 'Okto recusou o estorno.'),
                'error_code' => 'http_'.$response->status(),
                'raw' => $payload,
            ];
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));

        return [
            'success' => true,
            'pending' => ! in_array($status, ['reversed'], true),
            'message' => $status === 'reversed'
                ? 'Estorno Okto confirmado.'
                : 'Estorno Okto solicitado; aguardando webhook reversed.',
            'raw' => $payload,
        ];
    }

    /**
     * PUT /operator — cadastra URL única para cash-in e cash-out.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function registerWebhookUrls(array $credentials, string $url, ?string $notificationToken = null): void
    {
        $url = trim($url);
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Okto: URL de webhook precisa ser HTTPS pública.');
        }

        $body = [
            'depositUrl' => $url,
            'withdrawUrl' => $url,
        ];
        $token = trim((string) ($notificationToken ?? $credentials['notification_token'] ?? $this->accessToken($credentials)));
        if ($token !== '') {
            $body['notificationToken'] = $token;
        }

        $response = $this->http($credentials)->put($this->url($credentials, '/operator'), $body);
        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response, 'Falha ao registrar webhook na Okto.'));
        }
    }

    public static function verifyPayloadSignature(string $rawBody, string $signatureB64, string $publicKeyPem): bool
    {
        $pem = self::normalizePublicKeyPem($publicKeyPem);
        if ($pem === '' || trim($signatureB64) === '') {
            return false;
        }

        $binary = base64_decode($signatureB64, true);
        if ($binary === false || $binary === '') {
            return false;
        }

        try {
            $ok = @openssl_verify($rawBody, $binary, $pem, OPENSSL_ALGO_SHA256);
        } catch (\Throwable) {
            return false;
        }

        return $ok === 1;
    }

    public static function normalizePublicKeyPem(string $raw): string
    {
        $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if ($raw === '') {
            return '';
        }
        if (str_contains($raw, '\\n')) {
            $raw = str_replace('\\n', "\n", $raw);
        }
        if (! str_contains($raw, 'BEGIN')) {
            $wrapped = trim(chunk_split(preg_replace('/\s+/', '', $raw) ?? '', 64, "\n"));
            $raw = "-----BEGIN PUBLIC KEY-----\n".$wrapped."\n-----END PUBLIC KEY-----";
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function baseUrl(array $credentials): string
    {
        $sandbox = filter_var($credentials['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $sandbox ? self::STAGING_BASE : self::PRODUCTION_BASE;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function url(array $credentials, string $path): string
    {
        return rtrim($this->baseUrl($credentials), '/').$path;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials): string
    {
        return trim((string) ($credentials['access_token'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function http(array $credentials): PendingRequest
    {
        return Http::timeout(30)
            ->connectTimeout(8)
            ->acceptJson()
            ->asJson()
            ->withToken($this->accessToken($credentials));
    }

    private function errorMessage(Response $response, string $fallback): string
    {
        $json = $response->json();
        if (! is_array($json)) {
            return $fallback;
        }

        $list = $json['errorList'] ?? null;
        if (is_array($list) && isset($list[0]) && is_array($list[0])) {
            $msg = trim((string) ($list[0]['detailedMessage'] ?? $list[0]['message'] ?? ''));
            if ($msg !== '') {
                return $msg;
            }
        }

        foreach (['message', 'error', 'detailedMessage'] as $key) {
            $v = $json[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return $fallback;
    }

    private function mapDepositStatus(mixed $status): ?string
    {
        $s = strtolower(trim((string) $status));

        return match ($s) {
            'paid' => 'paid',
            'created', 'processing', 'reversing', 'reviewing' => 'pending',
            'expired', 'failed', 'reversed' => 'cancelled',
            default => null,
        };
    }

    private function mapWithdrawStatus(mixed $status): ?string
    {
        $s = strtolower(trim((string) $status));

        return match ($s) {
            'success' => 'paid',
            'created', 'processing' => 'pending',
            'failed', 'expired' => 'failed',
            default => null,
        };
    }

    public function normalizePixKeyType(string $pixKeyType, string $pixKey): string
    {
        $type = strtolower(trim($pixKeyType));
        if ($type === 'random') {
            return 'evp';
        }
        if (in_array($type, ['cpf', 'cnpj', 'email', 'phone', 'evp'], true)) {
            return $type;
        }

        $digits = BrazilianDocumentDigits::onlyDigits($pixKey);
        if (strlen($digits) === 11) {
            return 'cpf';
        }
        if (strlen($digits) === 14) {
            return 'cnpj';
        }
        if (str_contains($pixKey, '@')) {
            return 'email';
        }

        return 'evp';
    }

    public function formatDictKey(string $pixKey, string $type): string
    {
        if ($type === 'cpf') {
            return BrazilianDocumentDigits::onlyDigits($pixKey);
        }
        if ($type === 'phone') {
            $digits = BrazilianDocumentDigits::onlyDigits($pixKey);
            if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
                return '+'.$digits;
            }
            if ($digits !== '') {
                return '+55'.$digits;
            }
        }

        return trim($pixKey);
    }
}
