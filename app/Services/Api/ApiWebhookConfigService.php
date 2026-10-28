<?php

namespace App\Services\Api;

use App\Models\ApiApplication;
use App\Models\ApiApplicationWebhook;
use App\Models\ApiKey;
use App\Support\ApiWebhookEvents;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ApiWebhookConfigService
{
    /** Sentinel: do not change stored webhook_events. */
    public const EVENTS_UNCHANGED = '__events_unchanged__';

    /**
     * @param  list<string>|null|self::EVENTS_UNCHANGED  $events
     * @param  list<string>  $productIds
     */
    public function updateFromPanel(
        ApiApplication $app,
        ?string $url,
        array|string|null $events,
        ?string $providedSecret,
        bool $hadSecret,
        ?bool $webhookEnabled = null,
        ?bool $isActive = null,
        ?ApiApplicationWebhook $endpoint = null,
        ?string $name = null,
        array $productIds = [],
        bool $syncProducts = false,
        bool $forceCreate = false,
        ?string $environment = null,
    ): WebhookProvisionResult {
        $target = $forceCreate ? null : ($endpoint ?? $this->primaryEndpoint($app));
        $creating = $target === null;
        $sandbox = $creating
            ? $environment === ApiKey::ENV_SANDBOX
            : $target->environmentName() === ApiKey::ENV_SANDBOX;

        if ($creating && ( ! is_string($url) || trim($url) === '')) {
            $app->update([
                'webhook_url' => null,
            ]);

            return new WebhookProvisionResult($app->fresh());
        }

        if ($creating) {
            $this->assertCanCreate($app);
        }

        // Endpoint de sandbox nunca herda o secret de produção da aplicação (senão um evento de teste
        // sairia com assinatura válida de produção).
        $currentSecret = $target?->secret ?? ($sandbox ? null : $app->webhook_secret);
        $secret = strlen((string) $providedSecret) > 0
            ? $providedSecret
            : $currentSecret;
        if ($sandbox && strlen((string) $providedSecret) > 0 && $this->isLiveSecret($app, (string) $providedSecret)) {
            throw new InvalidArgumentException('Use um signing secret diferente do de produção no endpoint de sandbox.');
        }

        $newSecretGenerated = false;
        if (is_string($url) && $url !== '' && ! $secret) {
            $secret = $this->generateSecret();
            $newSecretGenerated = true;
        }

        $active = $webhookEnabled ?? $isActive ?? $target?->is_active ?? true;
        $resolvedEvents = $events === self::EVENTS_UNCHANGED
            ? ($target?->events ?? $app->webhook_events)
            : $events;

        $payload = [
            'api_application_id' => $app->id,
            'url' => is_string($url) && $url !== '' ? $url : (string) ($target?->url ?? ''),
            'secret' => $secret,
            'is_active' => (bool) $active,
        ];
        if ($name !== null) {
            $payload['name'] = trim($name) !== '' ? trim($name) : null;
        }
        if ($events !== self::EVENTS_UNCHANGED) {
            $payload['events'] = $resolvedEvents;
        } elseif ($creating) {
            $payload['events'] = ApiWebhookEvents::allSubscription();
        }

        if ($creating && ($payload['name'] ?? null) === null) {
            $payload['name'] = $this->nextDefaultName($app);
        }
        if ($creating && $sandbox) {
            $payload['environment'] = ApiKey::ENV_SANDBOX;
        }

        if ($creating) {
            $target = ApiApplicationWebhook::create($payload);
        } else {
            $target->update($payload);
        }

        if ($syncProducts) {
            $target->products()->sync($productIds);
        }

        // Endpoint de sandbox não mexe nos campos legados (URL/secret de produção da aplicação).
        if (! $sandbox) {
            $this->syncPrimary($app);
        }

        $revealed = null;
        if ($newSecretGenerated || (strlen((string) $providedSecret) > 0 && ! $hadSecret)) {
            $revealed = $secret;
        }

        return new WebhookProvisionResult($app->fresh(), $revealed);
    }

    /**
     * @param  array{
     *     name?: string|null,
     *     url: string,
     *     secret?: string|null,
     *     events?: list<string>|null,
     *     is_active?: bool,
     *     product_ids?: list<string>
     * }  $data
     */
    public function createEndpoint(ApiApplication $app, array $data): WebhookProvisionResult
    {
        $this->assertCanCreate($app);

        return $this->updateFromPanel(
            $app,
            $data['url'],
            array_key_exists('events', $data) ? $data['events'] : ApiWebhookEvents::allSubscription(),
            $data['secret'] ?? null,
            false,
            $data['is_active'] ?? true,
            null,
            null,
            $data['name'] ?? null,
            $data['product_ids'] ?? [],
            true,
            true,
            $data['environment'] ?? null,
        );
    }

    /**
     * @param  array{
     *     name?: string|null,
     *     url?: string|null,
     *     secret?: string|null,
     *     events?: list<string>|null|self::EVENTS_UNCHANGED,
     *     is_active?: bool|null,
     *     product_ids?: list<string>
     * }  $data
     */
    public function updateEndpoint(ApiApplicationWebhook $endpoint, array $data): WebhookProvisionResult
    {
        $app = $endpoint->application;
        $hadSecret = ($endpoint->secret ?? '') !== '';

        return $this->updateFromPanel(
            $app,
            $data['url'] ?? $endpoint->url,
            array_key_exists('events', $data) ? $data['events'] : self::EVENTS_UNCHANGED,
            $data['secret'] ?? null,
            $hadSecret,
            array_key_exists('is_active', $data) ? $data['is_active'] : null,
            null,
            $endpoint,
            array_key_exists('name', $data) ? $data['name'] : $endpoint->name,
            $data['product_ids'] ?? [],
            array_key_exists('product_ids', $data),
        );
    }

    public function deleteEndpoint(ApiApplicationWebhook $endpoint): void
    {
        $app = $endpoint->application;
        $wasSandbox = $endpoint->environmentName() === ApiKey::ENV_SANDBOX;
        $endpoint->products()->detach();
        $endpoint->delete();
        if (! $wasSandbox) {
            $this->syncPrimary($app);
        }
    }

    public function clearWebhook(ApiApplication $app): void
    {
        $primary = $this->primaryEndpoint($app);
        if ($primary !== null) {
            $this->deleteEndpoint($primary);

            return;
        }

        $app->update([
            'webhook_url' => null,
            'webhook_secret' => null,
            'webhook_events' => ApiWebhookEvents::allSubscription(),
            'webhook_enabled' => true,
        ]);
    }

    public function provisionForApi(
        ApiApplication $app,
        string $url,
        bool $enabled = true,
        bool $rotateSecret = false,
    ): WebhookProvisionResult {
        $primary = $this->primaryEndpoint($app);
        $previousUrl = (string) ($primary?->url ?? $app->webhook_url ?? '');
        $urlChanged = $previousUrl !== $url;
        $existingSecret = (string) ($primary?->secret ?? $app->webhook_secret ?? '');

        $revealed = null;
        $secret = $existingSecret;

        if ($rotateSecret || $secret === '' || $urlChanged) {
            $secret = $this->generateSecret();
            $revealed = $secret;
        }

        $result = $this->updateFromPanel(
            $app,
            $url,
            $primary ? self::EVENTS_UNCHANGED : ApiWebhookEvents::allSubscription(),
            $secret,
            $existingSecret !== '',
            $enabled,
            null,
            $primary,
        );

        $this->ensureAllEventsSubscribed($app);

        if ($revealed !== null) {
            return new WebhookProvisionResult($app->fresh(), $revealed);
        }

        return $result;
    }

    public function ensureAllEventsSubscribed(ApiApplication $app): void
    {
        ApiApplication::query()
            ->whereKey($app->id)
            ->update(['webhook_events' => ApiWebhookEvents::allSubscription()]);

        $primary = $this->primaryEndpoint($app);
        if ($primary !== null) {
            $primary->update(['events' => ApiWebhookEvents::allSubscription()]);
        }
    }

    public function rotateSecret(ApiApplication $app, ?ApiApplicationWebhook $endpoint = null): string
    {
        $target = $endpoint ?? $this->primaryEndpoint($app);
        if ($target === null && ! $app->webhook_url) {
            throw new InvalidArgumentException('Configure uma URL de webhook antes de rotacionar o secret.');
        }

        $secret = $this->generateSecret();
        if ($target !== null) {
            $target->update(['secret' => $secret]);
            $this->syncPrimary($app);
        } else {
            $app->update(['webhook_secret' => $secret]);
        }

        return $secret;
    }

    /**
     * @param  list<string>|null  $events
     * @return list<string>|null
     */
    public function normalizePanelEvents(?array $events): ?array
    {
        if (! is_array($events)) {
            return null;
        }

        $events = array_values(array_unique($events));
        if (ApiWebhookEvents::isAllSubscription($events)) {
            return ApiWebhookEvents::allSubscription();
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseArray(ApiApplication $app, ?string $revealedSecret = null): array
    {
        $events = $app->webhook_events;
        $allEvents = ApiWebhookEvents::isAllSubscription(is_array($events) ? $events : null);
        $hasSecret = ($app->webhook_secret ?? '') !== '';
        $enabled = Schema::hasColumn($app->getTable(), 'webhook_enabled')
            ? (bool) ($app->webhook_enabled ?? true)
            : true;

        $response = [
            'webhook_url' => $app->webhook_url,
            'webhook_enabled' => $enabled,
            'webhook_events' => $allEvents ? null : $events,
            'events_mode' => $allEvents ? 'all' : 'selected',
            'has_secret' => $hasSecret,
        ];

        if ($revealedSecret !== null && $revealedSecret !== '') {
            $response['webhook_secret'] = $revealedSecret;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPanelArray(ApiApplicationWebhook $endpoint): array
    {
        $endpoint->loadMissing('products:id,name');
        $events = $endpoint->events;
        $allEvents = ApiWebhookEvents::isAllSubscription(is_array($events) ? $events : null);

        return [
            'id' => $endpoint->id,
            'configured' => true,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'has_secret' => ($endpoint->secret ?? '') !== '',
            'events' => $allEvents ? null : $events,
            'events_mode' => $allEvents ? 'all' : 'selected',
            'is_active' => (bool) $endpoint->is_active,
            'environment' => $endpoint->environmentName(),
            'product_ids' => $endpoint->products->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'products' => $endpoint->products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ])->values()->all(),
        ];
    }

    /** Endpoint principal de produção (espelhado nos campos legados da aplicação). Sandbox nunca é principal. */
    public function primaryEndpoint(ApiApplication $app): ?ApiApplicationWebhook
    {
        return ApiApplicationWebhook::query()
            ->where('api_application_id', $app->id)
            ->when(Schema::hasColumn('api_application_webhooks', 'environment'), fn ($q) => $q->where(
                fn ($w) => $w->where('environment', '!=', ApiKey::ENV_SANDBOX)->orWhereNull('environment')
            ))
            ->orderBy('id')
            ->first();
    }

    private function isLiveSecret(ApiApplication $app, string $secret): bool
    {
        if (is_string($app->webhook_secret) && $app->webhook_secret !== '' && hash_equals($app->webhook_secret, $secret)) {
            return true;
        }

        return ApiApplicationWebhook::query()->where('api_application_id', $app->id)->get()
            ->contains(fn (ApiApplicationWebhook $e) => $e->environmentName() === ApiKey::ENV_LIVE && is_string($e->secret) && $e->secret !== '' && hash_equals($e->secret, $secret));
    }

    public function syncPrimary(ApiApplication $app): void
    {
        $primary = $this->primaryEndpoint($app);
        if ($primary === null) {
            $app->update([
                'webhook_url' => null,
                'webhook_secret' => null,
                'webhook_events' => ApiWebhookEvents::allSubscription(),
                'webhook_enabled' => true,
            ]);

            return;
        }

        $app->update([
            'webhook_url' => $primary->url,
            'webhook_secret' => $primary->secret,
            'webhook_events' => $primary->events,
            'webhook_enabled' => $primary->is_active,
        ]);
    }

    private function assertCanCreate(ApiApplication $app): void
    {
        $count = ApiApplicationWebhook::query()
            ->where('api_application_id', $app->id)
            ->count();
        if ($count >= ApiApplicationWebhook::MAX_PER_APPLICATION) {
            throw new InvalidArgumentException(
                'Limite de '.ApiApplicationWebhook::MAX_PER_APPLICATION.' webhooks por conta.'
            );
        }
    }

    private function nextDefaultName(ApiApplication $app): string
    {
        $count = ApiApplicationWebhook::query()
            ->where('api_application_id', $app->id)
            ->count();

        return 'Webhook '.($count + 1);
    }

    private function generateSecret(): string
    {
        return Str::random(32);
    }
}
