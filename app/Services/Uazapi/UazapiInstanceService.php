<?php

namespace App\Services\Uazapi;

use App\Exceptions\UazapiRequestException;
use App\Models\UazapiInstance;
use App\Support\PublicAppUrl;
use Illuminate\Support\Str;

class UazapiInstanceService
{
    public function __construct(
        private UazapiClient $client,
        private UazapiLabelService $labels,
    ) {}

    public function ensureInstance(int $tenantId): UazapiInstance
    {
        $instance = UazapiInstance::firstOrNewForTenant($tenantId);
        if (! $instance->exists) {
            $instance->save();
        }

        return $instance;
    }

    public function saveCredentials(UazapiInstance $instance, string $serverUrl, ?string $token = null): UazapiInstance
    {
        $normalized = $this->client->normalizeServerUrl($serverUrl);
        if ($normalized === '') {
            throw new UazapiRequestException('Informe a Server URL da sua conta uazapi.', 0, false);
        }

        $plainToken = $this->client->normalizeToken($token ?: (string) ($instance->instance_token ?? ''));

        $instance->server_url = $normalized;
        if ($plainToken !== '') {
            $instance->instance_token = $plainToken;
        }
        if (! is_string($instance->webhook_secret) || trim($instance->webhook_secret) === '') {
            $instance->webhook_secret = Str::lower(Str::random(48));
        }
        $instance->save();

        if (! $instance->hasCredentials()) {
            throw new UazapiRequestException('Informe o token da instância uazapi.', 0, false);
        }

        try {
            $response = $this->api($instance)->status($plainToken !== '' ? $plainToken : (string) $instance->instance_token);
            $this->applyRemoteState($instance, $response);
            $instance->last_error = null;
            $instance->save();
            $this->ensureWebhook($instance, true);
        } catch (UazapiRequestException $e) {
            $rewritten = $this->rewriteAuthError($instance, $plainToken !== '' ? $plainToken : (string) $instance->instance_token, $e);
            $instance->last_error = $rewritten->getMessage();
            $instance->save();
            throw $rewritten;
        }

        return $instance;
    }

    public function connect(UazapiInstance $instance): UazapiInstance
    {
        if (! $instance->hasCredentials()) {
            throw new UazapiRequestException('Informe a Server URL e o token da sua instância uazapi.', 0, false);
        }

        $token = (string) $instance->instance_token;
        $api = $this->api($instance);

        try {
            $this->ensureWebhook($instance, true);
        } catch (UazapiRequestException $e) {
            $instance->last_error = $e->getMessage();
            $instance->save();
        }

        try {
            $response = $api->connect($token);
        } catch (UazapiRequestException $e) {
            if ($e->status !== 409) {
                $instance->last_error = $e->getMessage();
                $instance->save();
                throw $e;
            }
            $response = $api->status($token);
        }

        $this->applyRemoteState($instance, $response);
        $instance->last_error = null;
        $instance->save();

        try {
            $this->labels->ensureDefaults($instance);
        } catch (\Throwable) {
            // conexão já estabelecida
        }

        return $instance;
    }

    public function refreshStatus(UazapiInstance $instance): UazapiInstance
    {
        if (! $instance->hasCredentials()) {
            return $instance;
        }

        try {
            $response = $this->api($instance)->status((string) $instance->instance_token);
            $this->applyRemoteState($instance, $response);
            $instance->last_error = null;
            $instance->save();
        } catch (UazapiRequestException $e) {
            $instance->last_error = $e->getMessage();
            $instance->save();
        }

        return $instance;
    }

    public function disconnect(UazapiInstance $instance): UazapiInstance
    {
        $token = (string) ($instance->instance_token ?? '');
        if ($token !== '' && $instance->hasCredentials()) {
            try {
                $this->api($instance)->disconnect($token);
            } catch (UazapiRequestException $e) {
                $instance->last_error = $e->getMessage();
            }
        }

        $instance->status = UazapiInstance::STATUS_DISCONNECTED;
        $instance->qrcode = null;
        $instance->paircode = null;
        $instance->connected_at = null;
        $instance->save();

        return $instance;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyWebhookPayload(UazapiInstance $instance, array $payload): void
    {
        $eventType = strtolower((string) ($payload['EventType'] ?? $payload['event'] ?? ''));
        if ($eventType !== 'connection') {
            return;
        }

        $this->applyRemoteState($instance, $payload);
        $instance->save();
    }

    public function webhookUrl(UazapiInstance $instance): string
    {
        return rtrim(PublicAppUrl::base(), '/').'/webhooks/uazapi/'.$instance->webhook_secret;
    }

    public function ensureWebhook(UazapiInstance $instance, bool $force = false): void
    {
        if (! $instance->hasCredentials()) {
            return;
        }

        if (! $force && $instance->webhook_synced_at && $instance->webhook_synced_at->gt(now()->subHour())) {
            return;
        }

        $this->syncWebhook($instance);
        $instance->webhook_synced_at = now();
        $instance->save();
    }

    public function syncWebhook(UazapiInstance $instance): void
    {
        if (! $instance->hasCredentials()) {
            return;
        }

        $this->api($instance)->configureWebhook((string) $instance->instance_token, [
            'enabled' => true,
            'url' => $this->webhookUrl($instance),
            'events' => ['connection', 'messages', 'messages_update'],
            'excludeMessages' => ['wasSentByApi'],
        ]);
    }

    private function api(UazapiInstance $instance): UazapiClient
    {
        return $this->client->using($instance);
    }

    private function rewriteAuthError(UazapiInstance $instance, string $token, UazapiRequestException $e): UazapiRequestException
    {
        if ($e->status !== 401) {
            return $e;
        }

        try {
            $this->api($instance)->listInstancesWithAdminToken($token);

            return new UazapiRequestException(
                'Este valor é o admintoken do servidor. No painel uazapi abra a instância e copie o token dela — não o token de administrador.',
                401,
                false
            );
        } catch (UazapiRequestException) {
            return new UazapiRequestException(
                'O servidor recusou o token. Use a Server URL da sua conta (https://seu-subdominio.uazapi.com) e o token da instância, não o admintoken.',
                401,
                false
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyRemoteState(UazapiInstance $instance, array $payload): void
    {
        $remote = $this->extractInstance($payload);
        $connectedFlag = $payload['connected'] ?? $payload['loggedIn'] ?? null;

        $status = (string) ($remote['status'] ?? '');
        if ($status === '' && $connectedFlag === true) {
            $status = UazapiInstance::STATUS_CONNECTED;
        }
        if ($status === '' && $connectedFlag === false) {
            $status = (string) ($instance->status ?: UazapiInstance::STATUS_CONNECTING);
        }

        if (in_array($status, [
            UazapiInstance::STATUS_DISCONNECTED,
            UazapiInstance::STATUS_CONNECTING,
            UazapiInstance::STATUS_CONNECTED,
            UazapiInstance::STATUS_HIBERNATED,
        ], true)) {
            $instance->status = $status;
        }

        if (! empty($remote['qrcode']) && is_string($remote['qrcode'])) {
            $instance->qrcode = $remote['qrcode'];
        }
        if (! empty($remote['paircode']) && is_string($remote['paircode'])) {
            $instance->paircode = $remote['paircode'];
        }
        if (! empty($remote['profileName']) && is_string($remote['profileName'])) {
            $instance->profile_name = $remote['profileName'];
        }
        if (! empty($remote['id'])) {
            $instance->instance_id = (string) $remote['id'];
        }

        $owner = $remote['owner'] ?? $payload['owner'] ?? null;
        $phone = $this->client->normalizePhone(is_string($owner) ? explode(':', $owner)[0] : null);
        if ($phone) {
            $instance->phone = $phone;
        }

        if ($instance->status === UazapiInstance::STATUS_CONNECTED) {
            $instance->qrcode = null;
            $instance->paircode = null;
            $instance->connected_at ??= now();
            $instance->last_error = null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractInstance(array $payload): array
    {
        $instance = $payload['instance'] ?? null;

        return is_array($instance) ? $instance : $payload;
    }
}
