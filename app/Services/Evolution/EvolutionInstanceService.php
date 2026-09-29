<?php

namespace App\Services\Evolution;

use App\Exceptions\EvolutionRequestException;
use App\Models\EvolutionInstance;
use App\Support\PublicAppUrl;
use Illuminate\Support\Str;

class EvolutionInstanceService
{
    public function __construct(private EvolutionClient $client) {}

    public function saveCredentials(
        EvolutionInstance $instance,
        string $serverUrl,
        string $instanceName,
        ?string $token = null
    ): EvolutionInstance {
        $normalizedUrl = $this->client->normalizeServerUrl($serverUrl);
        $normalizedName = $this->client->normalizeInstanceName($instanceName);
        $plainToken = $this->client->normalizeToken($token ?: (string) ($instance->instance_token ?? ''));

        if ($normalizedUrl === '') {
            throw new EvolutionRequestException('Informe a Server URL da sua Evolution API.', 0, false);
        }
        if ($normalizedName === '') {
            throw new EvolutionRequestException('Informe o nome da instância Evolution.', 0, false);
        }

        $instance->server_url = $normalizedUrl;
        $instance->instance_name = $normalizedName;
        if ($plainToken !== '') {
            $instance->instance_token = $plainToken;
        }
        if (! is_string($instance->webhook_secret) || trim($instance->webhook_secret) === '') {
            $instance->webhook_secret = Str::lower(Str::random(48));
        }
        $instance->save();

        if (! $instance->hasCredentials()) {
            throw new EvolutionRequestException('Informe o apikey da instância Evolution.', 0, false);
        }

        try {
            $response = $this->api($instance)->connectionState((string) $instance->instance_token, $normalizedName);
            $this->applyRemoteState($instance, $response);
            $instance->last_error = null;
            $instance->save();
            $this->ensureWebhook($instance, true);
        } catch (EvolutionRequestException $e) {
            $rewritten = $this->rewriteAuthError($e);
            $instance->last_error = $rewritten->getMessage();
            $instance->save();
            throw $rewritten;
        }

        return $instance;
    }

    public function connect(EvolutionInstance $instance): EvolutionInstance
    {
        if (! $instance->hasCredentials()) {
            throw new EvolutionRequestException('Informe a Server URL, o nome e o token da instância Evolution.', 0, false);
        }

        try {
            $this->ensureWebhook($instance, true);
        } catch (EvolutionRequestException $e) {
            $instance->last_error = $e->getMessage();
            $instance->save();
        }

        try {
            $response = $this->api($instance)->connect(
                (string) $instance->instance_token,
                (string) $instance->instance_name
            );
        } catch (EvolutionRequestException $e) {
            $instance->last_error = $e->getMessage();
            $instance->save();
            throw $e;
        }

        $this->applyRemoteState($instance, $response);
        $instance->last_error = null;
        $instance->save();

        return $instance;
    }

    public function refreshStatus(EvolutionInstance $instance): EvolutionInstance
    {
        if (! $instance->hasCredentials()) {
            return $instance;
        }

        try {
            $response = $this->api($instance)->connectionState(
                (string) $instance->instance_token,
                (string) $instance->instance_name
            );
            $this->applyRemoteState($instance, $response);
            $instance->last_error = null;
            $instance->save();
        } catch (EvolutionRequestException $e) {
            $instance->last_error = $e->getMessage();
            $instance->save();
        }

        return $instance;
    }

    public function disconnect(EvolutionInstance $instance): EvolutionInstance
    {
        if ($instance->hasCredentials()) {
            try {
                $this->api($instance)->logout(
                    (string) $instance->instance_token,
                    (string) $instance->instance_name
                );
            } catch (EvolutionRequestException $e) {
                $instance->last_error = $e->getMessage();
            }
        }

        $instance->status = EvolutionInstance::STATUS_DISCONNECTED;
        $instance->qrcode = null;
        $instance->paircode = null;
        $instance->connected_at = null;
        $instance->save();

        return $instance;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyWebhookPayload(EvolutionInstance $instance, array $payload): void
    {
        $this->applyRemoteState($instance, $payload);
        $instance->save();
    }

    public function webhookUrl(EvolutionInstance $instance): string
    {
        return rtrim(PublicAppUrl::base(), '/').'/webhooks/evolution/'.$instance->webhook_secret;
    }

    public function ensureWebhook(EvolutionInstance $instance, bool $force = false): void
    {
        if (! $instance->hasCredentials()) {
            return;
        }

        if (! $force && $instance->webhook_synced_at && $instance->webhook_synced_at->gt(now()->subHour())) {
            return;
        }

        $this->api($instance)->setWebhook((string) $instance->instance_token, (string) $instance->instance_name, [
            'enabled' => true,
            'url' => $this->webhookUrl($instance),
            'webhookByEvents' => false,
            'webhookBase64' => false,
            'events' => ['QRCODE_UPDATED', 'CONNECTION_UPDATE', 'MESSAGES_UPSERT'],
            'headers' => [
                'X-Stacker-Webhook' => (string) $instance->webhook_secret,
            ],
        ]);
        $instance->webhook_synced_at = now();
        $instance->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyRemoteState(EvolutionInstance $instance, array $payload): void
    {
        $state = $this->extractState($payload);
        if ($state !== null) {
            $instance->status = $state;
        }

        $qr = $this->firstString($payload, ['base64', 'qrcode', 'qr']);
        if ($qr === null && isset($payload['qrcode']) && is_array($payload['qrcode'])) {
            $qr = $this->firstString($payload['qrcode'], ['base64', 'code']);
        }
        if ($qr !== null) {
            $instance->qrcode = $qr;
            if ($instance->status === EvolutionInstance::STATUS_DISCONNECTED) {
                $instance->status = EvolutionInstance::STATUS_CONNECTING;
            }
        }

        $pair = $this->firstString($payload, ['pairingCode', 'paircode', 'code']);
        if ($pair !== null && ! str_starts_with($pair, '2@') && ! str_starts_with($pair, 'data:')) {
            $instance->paircode = $pair;
        }

        $phone = $this->extractPhone($payload);
        if ($phone) {
            $instance->phone = $phone;
        }

        $profile = $this->firstString($payload, ['profileName', 'profile_name', 'ownerJid']);
        if ($profile) {
            $instance->profile_name = $profile;
        }

        if ($instance->status === EvolutionInstance::STATUS_CONNECTED) {
            $instance->qrcode = null;
            $instance->paircode = null;
            $instance->connected_at ??= now();
            $instance->last_error = null;
        }
    }

    private function api(EvolutionInstance $instance): EvolutionClient
    {
        return $this->client->using($instance);
    }

    private function rewriteAuthError(EvolutionRequestException $e): EvolutionRequestException
    {
        if ($e->status !== 401) {
            return $e;
        }

        return new EvolutionRequestException(
            'O servidor recusou o token. Use a Server URL HTTPS, o nome da instância e o apikey da instância — não o apikey global do servidor.',
            401,
            false
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractState(array $payload): ?string
    {
        $raw = $payload['instance']['state']
            ?? $payload['state']
            ?? $payload['data']['state']
            ?? $payload['data']['instance']['state']
            ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return match (strtolower($raw)) {
            'open', 'connected' => EvolutionInstance::STATUS_CONNECTED,
            'connecting' => EvolutionInstance::STATUS_CONNECTING,
            'close', 'closed', 'disconnected' => EvolutionInstance::STATUS_DISCONNECTED,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractPhone(array $payload): ?string
    {
        $candidates = [
            $payload['instance']['ownerJid'] ?? null,
            $payload['instance']['wuid'] ?? null,
            $payload['owner'] ?? null,
            $payload['data']['wuid'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $jid = explode(':', explode('@', $candidate)[0])[0];
            $phone = $this->client->normalizePhone($jid);
            if ($phone) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach ($keys as $key) {
                $value = $data[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }
}
