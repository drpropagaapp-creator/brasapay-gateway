<?php

namespace App\Services\Xflow;

use App\Support\GatewayWebhookUrl;
use Illuminate\Support\Facades\Log;

/**
 * Registra endpoints de webhook na conta Xflow e guarda o secret HMAC (devolvido uma vez).
 */
class XflowWebhookBootstrapService
{
    /** @var list<string> */
    public const PIX_EVENTS = ['transaction.paid', 'transaction.refunded', 'transaction.refund_failed'];

    /** @var list<string> */
    public const PAYOUT_EVENTS = ['withdrawal.processing', 'withdrawal.completed', 'withdrawal.failed'];

    /** @var list<string> */
    public const DISPUTE_EVENTS = ['dispute.opened', 'dispute.accepted', 'dispute.rejected'];

    public function __construct(
        private readonly XflowHttpClient $client = new XflowHttpClient(),
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{credentials: array<string, mixed>, warning: ?string}
     */
    public function bootstrap(array $credentials): array
    {
        $warnings = [];

        $pixUrl = GatewayWebhookUrl::forGateway('xflow');
        if ($pixUrl === '' || str_contains($pixUrl, 'localhost') || ! str_starts_with($pixUrl, 'https://')) {
            return [
                'credentials' => $credentials,
                'warning' => 'Xflow: configure GETFY_WEBHOOK_PUBLIC_URL (HTTPS público) para registrar o webhook automaticamente.',
            ];
        }

        $pix = $this->ensureEndpoint(
            $credentials,
            $pixUrl,
            array_values(array_unique([...self::PIX_EVENTS, ...self::PAYOUT_EVENTS, ...self::DISPUTE_EVENTS])),
            'webhook_endpoint_id',
            'webhook_secret',
            'Stacker Gateway PIX, saques e MED',
        );
        $credentials = $pix['credentials'];
        if ($pix['warning'] !== null) {
            $warnings[] = $pix['warning'];
        }

        $payoutSecret = trim((string) ($credentials['payout_webhook_secret'] ?? ''));
        $disputeSecret = trim((string) ($credentials['dispute_webhook_secret'] ?? ''));
        $createdPixFresh = trim((string) ($credentials['webhook_secret'] ?? '')) !== ''
            && $pix['created'] === true;

        // Conta nova: o endpoint PIX já assina withdrawal.* e dispute.*. Conta antiga: endpoints extras.
        if (! $createdPixFresh && $payoutSecret === '') {
            $payout = $this->ensureEndpoint(
                $credentials,
                GatewayWebhookUrl::forGateway('xflow.payout'),
                self::PAYOUT_EVENTS,
                'payout_webhook_endpoint_id',
                'payout_webhook_secret',
                'Stacker Gateway saques PIX',
            );
            $credentials = $payout['credentials'];
            if ($payout['warning'] !== null) {
                $warnings[] = $payout['warning'];
            }
        }

        if (! $createdPixFresh && $disputeSecret === '') {
            $dispute = $this->ensureEndpoint(
                $credentials,
                GatewayWebhookUrl::forGateway('xflow.disputes'),
                self::DISPUTE_EVENTS,
                'dispute_webhook_endpoint_id',
                'dispute_webhook_secret',
                'Stacker Gateway MED Pix',
            );
            $credentials = $dispute['credentials'];
            if ($dispute['warning'] !== null) {
                $warnings[] = $dispute['warning'];
            }
        }

        return [
            'credentials' => $credentials,
            'warning' => $warnings === [] ? null : implode(' ', $warnings),
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $events
     * @return array{credentials: array<string, mixed>, warning: ?string, created: bool}
     */
    private function ensureEndpoint(
        array $credentials,
        string $url,
        array $events,
        string $idKey,
        string $secretKey,
        string $description,
    ): array {
        $existingId = trim((string) ($credentials[$idKey] ?? ''));
        $existingSecret = trim((string) ($credentials[$secretKey] ?? ''));
        if ($existingId !== '' && $existingSecret !== '') {
            return [
                'credentials' => $credentials,
                'warning' => null,
                'created' => false,
            ];
        }

        try {
            $listed = $this->findEndpointByUrl($credentials, $url);
        } catch (\Throwable $e) {
            Log::warning('XflowWebhookBootstrap: listagem falhou', ['error' => $e->getMessage(), 'url' => $url]);

            return [
                'credentials' => $credentials,
                'warning' => 'Xflow: não foi possível listar webhooks ('.$e->getMessage().'). Cadastre a URL no painel e cole o secret HMAC.',
                'created' => false,
            ];
        }

        if ($listed !== null) {
            $credentials[$idKey] = $listed['id'];
            if ($existingSecret !== '') {
                return [
                    'credentials' => $credentials,
                    'warning' => null,
                    'created' => false,
                ];
            }

            return [
                'credentials' => $credentials,
                'warning' => 'Xflow: o endpoint já existe no painel, mas o secret HMAC só é mostrado na criação. Cole-o no campo “Segredo do webhook” ou remova o endpoint e teste a conexão de novo.',
                'created' => false,
            ];
        }

        try {
            $created = $this->createEndpoint($credentials, $url, $events, $description);
        } catch (\Throwable $e) {
            Log::warning('XflowWebhookBootstrap: criação falhou', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return [
                'credentials' => $credentials,
                'warning' => 'Xflow: falha ao registrar webhook ('.$e->getMessage().'). Cadastre a URL no painel Integrações → Webhooks.',
                'created' => false,
            ];
        }

        if ($created['id'] !== '') {
            $credentials[$idKey] = $created['id'];
        }
        if ($created['secret'] !== '') {
            $credentials[$secretKey] = $created['secret'];
        }

        $warning = $created['secret'] === ''
            ? 'Xflow: webhook registrado, mas o secret HMAC não veio na resposta. Cole-o no campo “Segredo do webhook”.'
            : null;

        return [
            'credentials' => $credentials,
            'warning' => $warning,
            'created' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{id: string}|null
     */
    private function findEndpointByUrl(array $credentials, string $url): ?array
    {
        $response = $this->client->request($credentials, 'GET', 'webhooks');
        if (! $response->successful()) {
            throw new \RuntimeException($this->client->errorMessage($response));
        }

        $json = $response->json();
        $rows = is_array($json) ? ($json['data'] ?? $json) : null;
        if (! is_array($rows)) {
            return null;
        }

        $needle = rtrim($url, '/');
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowUrl = rtrim((string) ($row['url'] ?? ''), '/');
            if ($rowUrl === $needle) {
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '') {
                    return ['id' => $id];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $events
     * @return array{id: string, secret: string}
     */
    private function createEndpoint(array $credentials, string $url, array $events, string $description): array
    {
        $response = $this->client->request($credentials, 'POST', 'webhooks', [
            'url' => $url,
            'events' => $events,
            'description' => $description,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->client->errorMessage($response));
        }

        $json = $response->json();
        $created = is_array($json) ? XflowHttpClient::unwrapObject($json) : [];
        $id = trim((string) ($created['id'] ?? ''));
        $secret = trim((string) ($created['secret'] ?? $created['signing_secret'] ?? ''));

        return [
            'id' => $id,
            'secret' => $secret,
        ];
    }
}
