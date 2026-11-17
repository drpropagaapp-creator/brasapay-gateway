<?php

namespace App\Gateways\Cakto;

use App\Gateways\Contracts\GatewayDriver;
use App\Support\FakeConsumerData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cakto (API pública): cartão de crédito pela cobrança direta (POST /public_api/payments/).
 *
 * - Autenticação OAuth2 client_credentials (client_id + client_secret → access_token).
 * - A Cakto cobra sempre uma OFERTA (preço fixo) de um produto da conta. O BrasaPay usa um
 *   produto Cakto configurado na credencial e cria/reaproveita uma oferta por valor
 *   (tabela cakto_offers), para cobrar o total exato do pedido (com order bump, cupom e frete).
 * - O cartão é tokenizado no navegador pelo SDK da Cakto, junto com o perfil antifraude;
 *   o backend recebe só card_token + antifraud_reference + fingerprint.
 * - Apple Pay e Google Pay não existem na API de cobrança direta (só no checkout hospedado).
 *
 * @see https://docs.cakto.com.br/api-reference/payments/create-card
 */
class CaktoDriver implements GatewayDriver
{
    public const DEFAULT_BASE_URL = 'https://api.cakto.com.br';

    /** Preço mínimo de oferta aceito pela Cakto (R$). */
    public const MIN_OFFER_PRICE_BRL = 5.0;

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function baseUrl(array $credentials): string
    {
        $url = trim((string) ($credentials['base_url'] ?? ''));
        if ($url === '') {
            $url = (string) config('services.cakto.base_url', self::DEFAULT_BASE_URL);
        }

        return rtrim($url, '/');
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

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function productId(array $credentials): string
    {
        return trim((string) ($credentials['product_id'] ?? ''));
    }

    /**
     * Access token OAuth2, em cache até pouco antes de expirar.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function accessToken(array $credentials, bool $forceRefresh = false): string
    {
        $clientId = $this->clientId($credentials);
        $clientSecret = $this->clientSecret($credentials);
        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Cakto: Client ID e Client Secret são obrigatórios.');
        }

        $cacheKey = 'cakto:token:'.sha1($this->baseUrl($credentials).'|'.$clientId.'|'.$clientSecret);
        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl($credentials).'/public_api/token/', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Cakto: falha ao autenticar (HTTP '.$response->status().'). Confira Client ID e Client Secret.');
        }

        $token = trim((string) $response->json('access_token', ''));
        if ($token === '') {
            throw new \RuntimeException('Cakto: resposta de autenticação sem access_token.');
        }

        $expiresIn = (int) $response->json('expires_in', 3600);
        $ttl = max(60, $expiresIn - 120);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function http(array $credentials, int $timeout = 30): PendingRequest
    {
        return Http::withToken($this->accessToken($credentials))
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withOptions(['connect_timeout' => 10]);
    }

    /**
     * GET/POST com um retry quando o token expirou antes do cache (401).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function request(array $credentials, string $method, string $path, array $body = [], array $headers = [], int $timeout = 30): Response
    {
        $url = $this->baseUrl($credentials).$path;
        $send = function () use ($credentials, $method, $url, $body, $headers, $timeout): Response {
            $req = $this->http($credentials, $timeout)->withHeaders($headers);

            return $method === 'GET' ? $req->get($url, $body) : $req->post($url, $body);
        };

        $response = $send();
        if ($response->status() === 401) {
            $this->accessToken($credentials, true);
            $response = $send();
        }

        return $response;
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $productId = $this->productId($credentials);
            if ($productId === '') {
                // Sem produto, a autenticação ainda pode ser conferida.
                $this->accessToken($credentials, true);

                return true;
            }
            $response = $this->request($credentials, 'GET', '/public_api/products/'.rawurlencode($productId).'/');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::info('CaktoDriver testConnection falhou', ['message' => mb_substr($e->getMessage(), 0, 300)]);

            return false;
        }
    }

    /**
     * Oferta ativa da Cakto com o preço exato (cria e guarda na tabela cakto_offers na primeira vez).
     *
     * @param  array<string, mixed>  $credentials
     */
    public function resolveOfferId(array $credentials, float $amount): string
    {
        $productId = $this->productId($credentials);
        if ($productId === '') {
            throw new \RuntimeException('Cakto: informe o ID do produto Cakto na credencial.');
        }
        $amountCents = (int) round($amount * 100);
        if ($amountCents < (int) round(self::MIN_OFFER_PRICE_BRL * 100)) {
            throw new \RuntimeException('Cakto: o valor mínimo para cartão é R$ 5,00.');
        }

        $existing = DB::table('cakto_offers')
            ->where('cakto_product_id', $productId)
            ->where('amount_cents', $amountCents)
            ->value('offer_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $price = round($amountCents / 100, 2);
        $response = $this->request($credentials, 'POST', '/public_api/offers/', [
            'name' => 'BrasaPay R$ '.number_format($price, 2, ',', '.'),
            'price' => $price,
            'product' => $productId,
            'status' => 'active',
            'type' => 'unique',
            'units' => 1,
        ]);

        $offerId = trim((string) $response->json('id', ''));
        if (! $response->successful() || $offerId === '') {
            Log::warning('CaktoDriver: falha ao criar oferta', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new \RuntimeException('Cakto: não foi possível preparar a oferta para este valor.');
        }

        try {
            DB::table('cakto_offers')->insert([
                'cakto_product_id' => $productId,
                'amount_cents' => $amountCents,
                'offer_id' => $offerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            // Outra requisição criou a oferta deste valor ao mesmo tempo: usa a que ficou gravada.
            $winner = DB::table('cakto_offers')
                ->where('cakto_product_id', $productId)
                ->where('amount_cents', $amountCents)
                ->value('offer_id');
            if (is_string($winner) && $winner !== '') {
                return $winner;
            }
        }

        return $offerId;
    }

    /**
     * Telefone no formato E.164 sem "+": DDI + número (Brasil quando vier só DDD + número).
     */
    public static function normalizePhone(string $raw, int $seed = 0): string
    {
        $hasPlus = str_starts_with(ltrim($raw), '+');
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            $digits = preg_replace('/\D/', '', (string) (FakeConsumerData::getForGateway($seed)['phone'] ?? '')) ?? '';
            $hasPlus = false;
        }
        if (! $hasPlus && (strlen($digits) === 10 || strlen($digits) === 11)) {
            $digits = '55'.$digits;
        }

        return $digits;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{card_token: string, antifraud_reference: string, fingerprint: string, installments: int}
     */
    public static function parseCardPayload(array $card, string $externalId): array
    {
        $raw = $card['payment_token'] ?? '';
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $decoded = is_array($decoded) ? $decoded : [];

        $installments = (int) ($decoded['installments'] ?? $card['installments'] ?? 1);
        $fingerprint = trim((string) ($decoded['fingerprint'] ?? ''));
        if ($fingerprint === '') {
            $fingerprint = 'bp-'.substr(sha1('order:'.$externalId), 0, 32);
        }

        return [
            'card_token' => trim((string) ($decoded['card_token'] ?? '')),
            'antifraud_reference' => trim((string) ($decoded['antifraud_reference'] ?? '')),
            'fingerprint' => mb_substr($fingerprint, 0, 128),
            'installments' => max(1, min(12, $installments)),
        ];
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $parsed = self::parseCardPayload($card, $externalId);
        if ($parsed['card_token'] === '') {
            throw new \RuntimeException('Cakto: token do cartão ausente. Preencha o cartão novamente.');
        }
        if ($parsed['antifraud_reference'] === '') {
            throw new \RuntimeException('Cakto: verificação antifraude não concluída. Recarregue a página e tente de novo.');
        }

        $offerId = $this->resolveOfferId($credentials, $amount);

        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?? '';
        $customer = [
            'name' => trim((string) ($consumer['name'] ?? '')) ?: 'Cliente',
            'email' => trim((string) ($consumer['email'] ?? '')),
            'phone' => self::normalizePhone((string) ($consumer['phone'] ?? ''), (int) $externalId),
            'fingerprint' => $parsed['fingerprint'],
        ];
        if (strlen($document) === 11 || strlen($document) === 14) {
            $customer['docType'] = strlen($document) === 11 ? 'cpf' : 'cnpj';
            $customer['docNumber'] = $document;
        }

        $body = [
            'paymentMethod' => 'credit_card',
            'customer' => $customer,
            'items' => [['offerId' => $offerId, 'quantity' => 1, 'offerType' => 'main']],
            'card' => ['token' => $parsed['card_token']],
            'installments' => $parsed['installments'],
            'antifraud_profiling_attempt_reference' => $parsed['antifraud_reference'],
            'metadata' => ['brasapay_order_id' => (string) $externalId],
        ];

        $address = $consumer['address'] ?? null;
        if (is_array($address) && trim((string) ($address['zip_code'] ?? '')) !== '') {
            $body['address'] = array_filter([
                'zipcode' => (string) $address['zip_code'],
                'street' => (string) ($address['street_name'] ?? ''),
                'number' => (string) ($address['street_number'] ?? ''),
                'neighborhood' => (string) ($address['neighborhood'] ?? ''),
                'city' => (string) ($address['city'] ?? ''),
                'state' => (string) ($address['federal_unit'] ?? ''),
                'country' => 'BR',
            ], fn ($v) => $v !== '');
        }

        // Mesma chave para o mesmo token: reenvio não cobra duas vezes (token é de uso único).
        $idempotencyKey = 'brasapay-'.$externalId.'-'.substr(sha1($parsed['card_token']), 0, 24);

        $response = $this->request($credentials, 'POST', '/public_api/payments/', $body, [
            'X-Idempotency-Key' => $idempotencyKey,
        ], 60);

        if (! $response->successful()) {
            Log::warning('CaktoDriver createCardPayment falhou', [
                'order_id' => $externalId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 800),
            ]);
            throw new \RuntimeException('Cakto: '.$this->errorMessage($response));
        }

        $orderId = trim((string) $response->json('id', ''));
        $status = strtolower(trim((string) $response->json('status', '')));
        if ($orderId === '') {
            throw new \RuntimeException('Cakto: resposta sem identificador do pedido.');
        }

        return [
            'transaction_id' => $orderId,
            'status' => match ($status) {
                'paid' => 'paid',
                'declined', 'refused', 'canceled', 'cancelled' => 'refused',
                default => 'pending',
            },
            'metadata' => array_filter([
                'cakto_order_id' => $orderId,
                'cakto_ref_id' => (string) $response->json('refId', ''),
                'cakto_offer_id' => $offerId,
                'cakto_status' => $status,
            ], fn ($v) => $v !== ''),
        ];
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $detail = $json['detail'] ?? null;
            if (is_string($detail) && trim($detail) !== '') {
                return trim($detail);
            }
            foreach ($json as $field => $value) {
                $msg = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                if (trim($msg) !== '') {
                    return is_string($field) ? $field.': '.trim($msg) : trim($msg);
                }
            }
        }

        return 'pagamento não processado (HTTP '.$response->status().').';
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        throw new \RuntimeException('Cakto: PIX não está habilitado nesta integração.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('Cakto: boleto não é suportado.');
    }

    /**
     * Status do pedido na Cakto: paid → paid; recusado/cancelado/reembolsado → cancelled.
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $id = trim($transactionId);
        if ($id === '') {
            return null;
        }
        try {
            $response = $this->request($credentials, 'GET', '/public_api/orders/'.rawurlencode($id).'/');
        } catch (\Throwable $e) {
            Log::info('CaktoDriver getTransactionStatus falhou', ['id' => $id, 'message' => mb_substr($e->getMessage(), 0, 300)]);

            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        return self::mapOrderStatus((string) $response->json('status', ''));
    }

    public static function mapOrderStatus(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            'paid', 'authorized' => 'paid',
            'waiting_payment', 'processing', 'pending', 'in_analysis' => 'pending',
            'refused', 'declined', 'canceled', 'cancelled', 'refunded', 'chargedback', 'in_settlement' => 'cancelled',
            default => null,
        };
    }
}
