<?php

namespace App\Gateways\AssetPay;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AssetPay (api.assetpay.cash, plataforma Oceanfy) — PIX cash-in, cash-out e infrações MED.
 *
 * Autenticação: POST /oauth/token (client_credentials) → Bearer de 1 hora.
 * Duas credenciais separadas no painel AssetPay (Integrações → Credenciais API):
 *  - Cash In  (public_key / secret_key): cobranças /v1/cob, conta e infrações;
 *  - Cash Out (cashout_public_key / cashout_secret_key): transferências /v1/transfers,
 *    só a partir de IPs cadastrados na credencial.
 * Todos os valores da API em CENTAVOS.
 *
 * @see https://docs.assetpay.cash/docs
 */
class AssetPayDriver implements GatewayDriver
{
    public const BASE_URL = 'https://api.assetpay.cash';

    public const SCOPE_CASH_IN = 'cash_in';

    public const SCOPE_CASH_OUT = 'cash_out';

    /** Prefixo do externalId das transferências: "saque-{id do saque}". */
    public const TRANSFER_EXTERNAL_PREFIX = 'saque-';

    public function testConnection(array $credentials): bool
    {
        try {
            $response = $this->http($credentials, self::SCOPE_CASH_IN)
                ->timeout(10)
                ->get($this->url('/v1/accounts'));
        } catch (\Throwable $e) {
            Log::debug('AssetPayDriver testConnection error', ['message' => $e->getMessage()]);

            return false;
        }

        return $response->successful();
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function testCashOut(array $credentials): array
    {
        try {
            $response = $this->http($credentials, self::SCOPE_CASH_OUT)
                ->timeout(10)
                ->get($this->url('/v1/transfers'), ['page' => 1, 'pageSize' => 1]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($response->status() === 403) {
            return ['ok' => false, 'error' => self::ipErrorMessage($response)];
        }

        return $response->successful()
            ? ['ok' => true]
            : ['ok' => false, 'error' => $this->errorMessage($response->json(), 'HTTP '.$response->status())];
    }

    /**
     * Saldo disponível para saque, em reais (null se a API não respondeu).
     */
    public function availableBalanceBrl(array $credentials): ?float
    {
        try {
            $response = $this->http($credentials, self::SCOPE_CASH_IN)
                ->timeout(10)
                ->get($this->url('/v1/accounts/balances'));
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful() || ! is_numeric($response->json('amount'))) {
            return null;
        }

        return round(((int) $response->json('amount')) / 100, 2);
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
        if ($amountCents < 100) {
            throw new \RuntimeException('AssetPay: o valor mínimo da cobrança PIX é R$ 1,00.');
        }

        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));
        $customer = [
            'name' => $this->sanitizeName((string) ($consumer['name'] ?? '')),
            'document' => [
                'number' => $document,
                'type' => strlen($document) === 14 ? 'cnpj' : 'cpf',
            ],
        ];
        $email = $this->sanitizeEmail((string) ($consumer['email'] ?? ''));
        if ($email !== '') {
            $customer['email'] = $email;
        }
        $phone = preg_replace('/\D/', '', (string) ($consumer['phone'] ?? ''));
        if (is_string($phone) && strlen($phone) >= 10 && strlen($phone) <= 13) {
            $customer['phone'] = $phone;
        }

        $body = [
            'amount' => $amountCents,
            'paymentMethod' => 'pix',
            'externalRef' => $externalId,
            'metadata' => ['order_id' => $externalId],
            'customer' => $customer,
            'items' => [
                [
                    'title' => $this->sanitizeName((string) ($options['description'] ?? ('Pedido '.$externalId))),
                    'unitPrice' => $amountCents,
                    'quantity' => 1,
                    'tangible' => false,
                ],
            ],
            'pix' => ['expiresInDays' => 1],
        ];

        $postback = $this->validPostbackUrl($postbackUrl);
        if ($postback !== null) {
            $body['postbackUrl'] = $postback;
        }

        try {
            $response = $this->http($credentials, self::SCOPE_CASH_IN)->post($this->url('/v1/cob'), $body);
        } catch (\RuntimeException $e) {
            throw $e;
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
            throw new \RuntimeException('AssetPay: resposta sem id da cobrança.');
        }

        $emv = trim((string) ($payload['pix']['qrcode'] ?? ''));

        return [
            'transaction_id' => $transactionId,
            'qrcode' => null,
            'copy_paste' => $emv !== '' ? $emv : null,
            'raw' => $payload,
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $charge = $this->getCharge($transactionId, $credentials);
        if ($charge === null) {
            return null;
        }

        return match (strtoupper(trim((string) ($charge['status'] ?? '')))) {
            'CONCLUIDA' => 'paid',
            'ATIVA' => 'pending',
            'EXPIRADA', 'REJEITADA', 'REMOVIDA_PELO_USUARIO' => 'cancelled',
            default => null,
        };
    }

    /**
     * GET /v1/cob/{id} — cobrança completa (null em 404 ou falha).
     *
     * @return array<string, mixed>|null
     */
    public function getCharge(string $transactionId, array $credentials): ?array
    {
        try {
            $response = $this->http($credentials, self::SCOPE_CASH_IN)
                ->timeout(12)
                ->get($this->url('/v1/cob/'.rawurlencode($transactionId)));
        } catch (\Throwable $e) {
            Log::warning('AssetPayDriver getCharge error', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * GET /v1/infractions/{id} — infração MED (null em 404 ou falha).
     *
     * @return array<string, mixed>|null
     */
    public function getInfraction(string $infractionId, array $credentials): ?array
    {
        try {
            $response = $this->http($credentials, self::SCOPE_CASH_IN)
                ->timeout(12)
                ->get($this->url('/v1/infractions/'.rawurlencode($infractionId)));
        } catch (\Throwable $e) {
            Log::warning('AssetPayDriver getInfraction error', [
                'infraction_id' => $infractionId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * Saque PIX (POST /v1/transfers). `$amount` em REAIS = valor que chega ao recebedor;
     * a tarifa da AssetPay é debitada do saldo à parte.
     *
     * @return array{ok: bool, transaction_id?: string|null, status?: string, error?: string}
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
        if ($type === 'phone') {
            $key = $this->normalizePhoneKey($key);
        }
        if ($key === '' || $type === '') {
            return ['ok' => false, 'error' => 'Chave PIX de destino inválida.'];
        }

        $amountCents = (int) round($amount * 100);
        if ($amountCents < 100) {
            return ['ok' => false, 'error' => 'O valor mínimo de transferência na AssetPay é R$ 1,00.'];
        }

        $body = [
            'amount' => $amountCents,
            'pixKeyType' => $type,
            'pixKey' => $key,
            'externalId' => self::TRANSFER_EXTERNAL_PREFIX.$externalId,
        ];
        $postback = $this->validPostbackUrl($postbackUrl);
        if ($postback !== null) {
            $body['postbackUrl'] = $postback;
        }

        try {
            $response = $this->http($credentials, self::SCOPE_CASH_OUT)->post($this->url('/v1/transfers'), $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'AssetPay: '.$e->getMessage()];
        }

        // 409: externalId já usado — a transferência original já existe (reenvio seguro).
        if ($response->status() === 409) {
            $transfer = is_array($response->json('transfer')) ? $response->json('transfer') : [];

            return $this->transferResult($transfer);
        }
        if ($response->status() === 403) {
            return ['ok' => false, 'error' => 'AssetPay: '.self::ipErrorMessage($response)];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'AssetPay: '.$this->errorMessage($response->json(), 'Erro ao enviar o saque PIX.')];
        }

        $payload = $response->json();

        return $this->transferResult(is_array($payload) ? $payload : []);
    }

    /**
     * Status do saque (GET /v1/transfers/{txId ou externalId}).
     * Retorna 'paid', 'pending', 'failed', 'cancelled' ou null.
     */
    public function getCashoutStatus(string $withdrawId, array $credentials): ?string
    {
        $transfer = $this->getTransfer($withdrawId, $credentials);
        if ($transfer === null) {
            return null;
        }

        return self::mapTransferStatus((string) ($transfer['status'] ?? ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTransfer(string $withdrawId, array $credentials): ?array
    {
        try {
            $response = $this->http($credentials, self::SCOPE_CASH_OUT)
                ->timeout(12)
                ->get($this->url('/v1/transfers/'.rawurlencode($withdrawId)));
        } catch (\Throwable $e) {
            Log::warning('AssetPayDriver getTransfer error', [
                'withdraw_id' => $withdrawId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    public static function mapTransferStatus(string $status): ?string
    {
        return match (strtoupper(trim($status))) {
            'LIQUIDATED' => 'paid',
            'PENDING', 'PROCESSING' => 'pending',
            'FAILED' => 'failed',
            'CANCELED', 'CANCELLED' => 'cancelled',
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
     * @param  array<string, mixed>  $transfer
     * @return array{ok: bool, transaction_id?: string|null, status?: string, error?: string}
     */
    private function transferResult(array $transfer): array
    {
        $status = strtoupper(trim((string) ($transfer['status'] ?? '')));
        if (in_array($status, ['FAILED', 'CANCELED', 'CANCELLED'], true)) {
            $reason = trim((string) ($transfer['errorMessage'] ?? ''));

            return ['ok' => false, 'error' => 'AssetPay recusou o PIX'.($reason !== '' ? ': '.$reason : '.')];
        }

        $txId = trim((string) ($transfer['txId'] ?? ''));

        return [
            'ok' => true,
            'transaction_id' => $txId !== '' ? $txId : null,
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function http(array $credentials, string $scope): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->withOptions(['connect_timeout' => 8])
            ->withToken($this->accessToken($credentials, $scope));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials, string $scope): string
    {
        [$clientId, $clientSecret] = self::clientCredentials($credentials, $scope);
        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException($scope === self::SCOPE_CASH_OUT
                ? 'AssetPay: configure a credencial Cash Out (chave pública e secreta) da adquirente.'
                : 'AssetPay: configure a credencial Cash In (chave pública e secreta) da adquirente.');
        }

        $cacheKey = 'assetpay:token:'.hash('sha256', $clientId.'|'.$clientSecret);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(15)
                ->withOptions(['connect_timeout' => 8])
                ->post($this->url('/oauth/token'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('AssetPay: falha de comunicação ao autenticar.', 0, $e);
        }

        $token = trim((string) $response->json('access_token', ''));
        if (! $response->successful() || $token === '') {
            $detail = $response->status() === 403 || $response->status() === 401
                ? self::ipErrorMessage($response, $scope)
                : $this->errorMessage($response->json(), 'HTTP '.$response->status());

            throw new \RuntimeException('AssetPay: autenticação recusada ('.$detail.').');
        }

        $ttl = (int) $response->json('expires_in', 3600);
        Cache::put($cacheKey, $token, max(60, $ttl - 120));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{0: string, 1: string}
     */
    public static function clientCredentials(array $credentials, string $scope): array
    {
        if ($scope === self::SCOPE_CASH_OUT) {
            return [
                trim((string) ($credentials['cashout_public_key'] ?? '')),
                trim((string) ($credentials['cashout_secret_key'] ?? '')),
            ];
        }

        return [
            trim((string) ($credentials['public_key'] ?? '')),
            trim((string) ($credentials['secret_key'] ?? '')),
        ];
    }

    private static function ipErrorMessage(Response $response, string $scope = self::SCOPE_CASH_OUT): string
    {
        $message = '';
        $json = $response->json();
        if (is_array($json)) {
            foreach (['message', 'error_description', 'error'] as $k) {
                if (is_string($json[$k] ?? null) && trim($json[$k]) !== '') {
                    $message = trim($json[$k]);
                    break;
                }
            }
        }
        $hint = $scope === self::SCOPE_CASH_OUT
            ? 'confira se o IP do servidor está cadastrado na credencial Cash Out'
            : 'confira a chave pública e a secreta';

        return ($message !== '' ? $message.' — ' : '').$hint;
    }

    private function url(string $path): string
    {
        return rtrim(self::BASE_URL, '/').$path;
    }

    private function normalizePixKeyType(string $type, string $key): string
    {
        $mapped = match (strtolower(trim($type))) {
            'cpf' => 'cpf',
            'cnpj' => 'cnpj',
            'email', 'e-mail' => 'email',
            'phone', 'telefone', 'celular' => 'phone',
            'random', 'evp', 'aleatoria', 'aleatória', 'chave_aleatoria' => 'random',
            default => '',
        };
        if ($mapped !== '') {
            return $mapped;
        }
        if (str_contains($key, '@')) {
            return 'email';
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($key)) === 1) {
            return 'random';
        }
        $digits = preg_replace('/\D/', '', $key);
        $digits = is_string($digits) ? $digits : '';
        if (str_starts_with(trim($key), '+')) {
            return 'phone';
        }
        if (strlen($digits) === 11) {
            return preg_match('/^[1-9]{2}9\d{8}$/', $digits) === 1 ? 'phone' : 'cpf';
        }
        if (strlen($digits) === 14) {
            return 'cnpj';
        }
        if (strlen($digits) >= 10 && strlen($digits) <= 13) {
            return 'phone';
        }

        return 'random';
    }

    /**
     * Telefone no formato DICT (+55DDDNÚMERO).
     */
    private function normalizePhoneKey(string $key): string
    {
        $digits = preg_replace('/\D/', '', $key);
        $digits = is_string($digits) ? $digits : '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 11) {
            $digits = '55'.$digits;
        }

        return '+'.$digits;
    }

    private function errorMessage(mixed $json, string $fallback): string
    {
        if (! is_array($json)) {
            return $fallback;
        }

        foreach (['message', 'error_description', 'error'] as $key) {
            $value = $json[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_array($value)) {
                $parts = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $value));
                if ($parts !== []) {
                    return implode(' ', array_slice($parts, 0, 3));
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
