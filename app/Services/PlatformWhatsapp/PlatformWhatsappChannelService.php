<?php

namespace App\Services\PlatformWhatsapp;

use App\Exceptions\EvolutionRequestException;
use App\Exceptions\UazapiRequestException;
use App\Models\PlatformWhatsappChannel;
use App\Services\Evolution\EvolutionClient;
use App\Services\Evolution\EvolutionInstanceService;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiInstanceService;
use App\Support\PublicAppUrl;
use Illuminate\Support\Str;
use RuntimeException;

class PlatformWhatsappChannelService
{
    public function __construct(
        private UazapiClient $uazapi,
        private EvolutionClient $evolution,
        private UazapiInstanceService $uazapiStates,
        private EvolutionInstanceService $evolutionStates,
    ) {}

    public function saveCredentials(
        PlatformWhatsappChannel $channel,
        string $provider,
        string $serverUrl,
        ?string $instanceName,
        ?string $token = null
    ): PlatformWhatsappChannel {
        if (! in_array($provider, PlatformWhatsappChannel::providers(), true)) {
            throw new RuntimeException('Escolha Uazapi ou Evolution API.');
        }

        $normalizedUrl = $provider === PlatformWhatsappChannel::PROVIDER_EVOLUTION
            ? $this->evolution->normalizeServerUrl($serverUrl)
            : $this->uazapi->normalizeServerUrl($serverUrl);

        if ($normalizedUrl === '') {
            throw new RuntimeException('Informe a Server URL.');
        }

        $plainToken = $provider === PlatformWhatsappChannel::PROVIDER_EVOLUTION
            ? $this->evolution->normalizeToken($token ?: (string) ($channel->instance_token ?? ''))
            : $this->uazapi->normalizeToken($token ?: (string) ($channel->instance_token ?? ''));

        $name = $this->evolution->normalizeInstanceName($instanceName ?: (string) ($channel->instance_name ?? ''));
        if ($provider === PlatformWhatsappChannel::PROVIDER_EVOLUTION && $name === '') {
            throw new RuntimeException('Informe o nome da instância Evolution.');
        }

        $providerChanged = $channel->provider !== $provider;
        $channel->provider = $provider;
        $channel->server_url = $normalizedUrl;
        $channel->instance_name = $provider === PlatformWhatsappChannel::PROVIDER_EVOLUTION ? $name : null;
        if ($plainToken !== '') {
            $channel->instance_token = $plainToken;
        }
        if (! is_string($channel->webhook_secret) || trim($channel->webhook_secret) === '') {
            $channel->webhook_secret = Str::lower(Str::random(48));
        }
        if ($providerChanged) {
            $channel->status = PlatformWhatsappChannel::STATUS_DISCONNECTED;
            $channel->qrcode = null;
            $channel->paircode = null;
            $channel->connected_at = null;
        }
        $channel->save();

        if (! $channel->hasCredentials()) {
            throw new RuntimeException('Informe o token da instância.');
        }

        try {
            $this->refreshStatus($channel);
            $this->ensureWebhook($channel, true);
        } catch (RuntimeException $e) {
            $channel->last_error = $e->getMessage();
            $channel->save();
            throw $e;
        }

        return $channel;
    }

    public function connect(PlatformWhatsappChannel $channel): PlatformWhatsappChannel
    {
        if (! $channel->hasCredentials()) {
            throw new RuntimeException('Salve a Server URL e o token da instância antes de conectar.');
        }

        try {
            $this->ensureWebhook($channel, true);
        } catch (RuntimeException $e) {
            $channel->last_error = $e->getMessage();
            $channel->save();
        }

        try {
            $response = $this->connectRemote($channel);
        } catch (RuntimeException $e) {
            $channel->last_error = $e->getMessage();
            $channel->save();
            throw $e;
        }

        $this->applyRemoteState($channel, $response);
        $channel->last_error = null;
        $channel->save();

        return $channel;
    }

    public function refreshStatus(PlatformWhatsappChannel $channel): PlatformWhatsappChannel
    {
        if (! $channel->hasCredentials()) {
            return $channel;
        }

        try {
            $response = $this->statusRemote($channel);
            $this->applyRemoteState($channel, $response);
            $channel->last_error = null;
            $channel->save();
        } catch (RuntimeException $e) {
            $channel->last_error = $e->getMessage();
            $channel->save();
        }

        return $channel;
    }

    public function disconnect(PlatformWhatsappChannel $channel): PlatformWhatsappChannel
    {
        if ($channel->hasCredentials()) {
            try {
                $this->logoutRemote($channel);
            } catch (RuntimeException $e) {
                $channel->last_error = $e->getMessage();
            }
        }

        $channel->status = PlatformWhatsappChannel::STATUS_DISCONNECTED;
        $channel->qrcode = null;
        $channel->paircode = null;
        $channel->connected_at = null;
        $channel->save();

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyWebhookPayload(PlatformWhatsappChannel $channel, array $payload): void
    {
        $this->applyRemoteState($channel, $payload);
        $channel->save();
    }

    public function webhookUrl(PlatformWhatsappChannel $channel): string
    {
        return rtrim(PublicAppUrl::base(), '/').'/webhooks/platform-whatsapp/'.$channel->webhook_secret;
    }

    public function ensureWebhook(PlatformWhatsappChannel $channel, bool $force = false): void
    {
        if (! $channel->hasCredentials()) {
            return;
        }

        if (! $force && $channel->webhook_synced_at && $channel->webhook_synced_at->gt(now()->subHour())) {
            return;
        }

        $this->syncWebhook($channel);
        $channel->webhook_synced_at = now();
        $channel->save();
    }

    public function sendText(PlatformWhatsappChannel $channel, string $phone, string $message): void
    {
        if (! $channel->canSend()) {
            throw new RuntimeException('Conecte o canal WhatsApp da plataforma para enviar.');
        }

        $token = (string) $channel->instance_token;
        if ($channel->isEvolution()) {
            $this->evolution->usingServer($channel->server_url)->sendText(
                $token,
                (string) $channel->instance_name,
                $phone,
                $message
            );

            return;
        }

        $this->uazapi->usingServer($channel->server_url)->sendText($token, [
            'number' => $phone,
            'text' => $message,
            'track_source' => 'stacker-platform',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyRemoteState(PlatformWhatsappChannel $channel, array $payload): void
    {
        if ($channel->isEvolution()) {
            $this->applyEvolutionState($channel, $payload);

            return;
        }

        $this->applyUazapiState($channel, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyUazapiState(PlatformWhatsappChannel $channel, array $payload): void
    {
        $dummy = new \App\Models\UazapiInstance;
        $dummy->status = $channel->status;
        $this->uazapiStates->applyRemoteState($dummy, $payload);
        $channel->status = $dummy->status ?: $channel->status;
        $channel->qrcode = $dummy->qrcode;
        $channel->paircode = $dummy->paircode;
        $channel->profile_name = $dummy->profile_name;
        $channel->phone = $dummy->phone;
        if ($channel->status === PlatformWhatsappChannel::STATUS_CONNECTED) {
            $channel->qrcode = null;
            $channel->paircode = null;
            $channel->connected_at ??= now();
            $channel->last_error = null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyEvolutionState(PlatformWhatsappChannel $channel, array $payload): void
    {
        $dummy = new \App\Models\EvolutionInstance;
        $dummy->status = $channel->status;
        $this->evolutionStates->applyRemoteState($dummy, $payload);
        $channel->status = $dummy->status ?: $channel->status;
        $channel->qrcode = $dummy->qrcode;
        $channel->paircode = $dummy->paircode;
        $channel->profile_name = $dummy->profile_name;
        $channel->phone = $dummy->phone;
        if ($channel->status === PlatformWhatsappChannel::STATUS_CONNECTED) {
            $channel->qrcode = null;
            $channel->paircode = null;
            $channel->connected_at ??= now();
            $channel->last_error = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function connectRemote(PlatformWhatsappChannel $channel): array
    {
        try {
            if ($channel->isEvolution()) {
                return $this->evolution->usingServer($channel->server_url)->connect(
                    (string) $channel->instance_token,
                    (string) $channel->instance_name
                );
            }

            return $this->uazapi->usingServer($channel->server_url)->connect((string) $channel->instance_token);
        } catch (UazapiRequestException|EvolutionRequestException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function statusRemote(PlatformWhatsappChannel $channel): array
    {
        try {
            if ($channel->isEvolution()) {
                return $this->evolution->usingServer($channel->server_url)->connectionState(
                    (string) $channel->instance_token,
                    (string) $channel->instance_name
                );
            }

            return $this->uazapi->usingServer($channel->server_url)->status((string) $channel->instance_token);
        } catch (UazapiRequestException|EvolutionRequestException $e) {
            throw new RuntimeException($this->rewriteAuthError($e->getMessage(), $e->status ?? 0), 0, $e);
        }
    }

    private function logoutRemote(PlatformWhatsappChannel $channel): void
    {
        try {
            if ($channel->isEvolution()) {
                $this->evolution->usingServer($channel->server_url)->logout(
                    (string) $channel->instance_token,
                    (string) $channel->instance_name
                );

                return;
            }

            $this->uazapi->usingServer($channel->server_url)->disconnect((string) $channel->instance_token);
        } catch (UazapiRequestException|EvolutionRequestException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function syncWebhook(PlatformWhatsappChannel $channel): void
    {
        $url = $this->webhookUrl($channel);
        try {
            if ($channel->isEvolution()) {
                $this->evolution->usingServer($channel->server_url)->setWebhook(
                    (string) $channel->instance_token,
                    (string) $channel->instance_name,
                    [
                        'enabled' => true,
                        'url' => $url,
                        'webhookByEvents' => false,
                        'webhookBase64' => false,
                        'events' => ['QRCODE_UPDATED', 'CONNECTION_UPDATE', 'MESSAGES_UPSERT'],
                        'headers' => [
                            'X-Stacker-Webhook' => (string) $channel->webhook_secret,
                        ],
                    ]
                );

                return;
            }

            $this->uazapi->usingServer($channel->server_url)->configureWebhook((string) $channel->instance_token, [
                'enabled' => true,
                'url' => $url,
                'events' => ['connection', 'messages', 'messages_update'],
                'excludeMessages' => ['wasSentByApi'],
            ]);
        } catch (UazapiRequestException|EvolutionRequestException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function rewriteAuthError(string $message, int $status): string
    {
        if ($status !== 401) {
            return $message;
        }

        return 'O servidor recusou o token. Use a Server URL HTTPS e o apikey da instância — não o token global do servidor.';
    }
}
