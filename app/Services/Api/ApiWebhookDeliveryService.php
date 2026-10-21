<?php

namespace App\Services\Api;

use App\Jobs\DeliverApiWebhookJob;
use App\Models\ApiApplication;
use App\Models\ApiApplicationWebhook;
use App\Models\ApiWebhookDelivery;
use App\Support\WebhookUrlValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ApiWebhookDeliveryService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        ApiApplication $app,
        string $event,
        array $payload,
        ?int $apiKeyId = null,
        mixed $productId = null,
    ): ?ApiWebhookDelivery {
        $first = null;
        foreach ($this->createDeliveryRecords($app, $event, $payload, $apiKeyId, $productId) as $delivery) {
            $first ??= $delivery;
            DeliverApiWebhookJob::dispatch($delivery->id);
        }

        return $first;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatchSync(
        ApiApplication $app,
        string $event,
        array $payload,
        ?int $apiKeyId = null,
        mixed $productId = null,
    ): void {
        foreach ($this->createDeliveryRecords($app, $event, $payload, $apiKeyId, $productId) as $delivery) {
            DeliverApiWebhookJob::dispatchSync($delivery->id);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ApiWebhookDelivery>
     */
    private function createDeliveryRecords(
        ApiApplication $app,
        string $event,
        array $payload,
        ?int $apiKeyId,
        mixed $productId,
    ): array {
        if (! $app->is_active) {
            return [];
        }

        $endpoints = ApiApplicationWebhook::query()
            ->where('api_application_id', $app->id)
            ->where('is_active', true)
            ->with('products:id')
            ->orderBy('id')
            ->get();

        if ($endpoints->isNotEmpty()) {
            $deliveries = [];
            foreach ($endpoints as $endpoint) {
                $delivery = $this->createForEndpoint($app, $endpoint, $event, $payload, $apiKeyId, $productId);
                if ($delivery !== null) {
                    $deliveries[] = $delivery;
                }
            }

            return $deliveries;
        }

        $legacy = $this->createLegacyRecord($app, $event, $payload, $apiKeyId);

        return $legacy !== null ? [$legacy] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createForEndpoint(
        ApiApplication $app,
        ApiApplicationWebhook $endpoint,
        string $event,
        array $payload,
        ?int $apiKeyId,
        mixed $productId,
    ): ?ApiWebhookDelivery {
        if (! $endpoint->isSubscribedTo($event) || ! $endpoint->appliesToProduct($productId)) {
            return null;
        }

        return $this->persistDelivery($app, $event, $payload, $apiKeyId, $endpoint->url, $endpoint->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createLegacyRecord(
        ApiApplication $app,
        string $event,
        array $payload,
        ?int $apiKeyId,
    ): ?ApiWebhookDelivery {
        if (! $app->webhook_url) {
            return null;
        }

        if (Schema::hasColumn($app->getTable(), 'webhook_enabled') && $app->webhook_enabled === false) {
            return null;
        }

        $subscribed = $app->webhook_events;
        if (is_array($subscribed) && count($subscribed) > 0 && ! in_array($event, $subscribed, true)) {
            return null;
        }

        return $this->persistDelivery($app, $event, $payload, $apiKeyId, (string) $app->webhook_url, null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistDelivery(
        ApiApplication $app,
        string $event,
        array $payload,
        ?int $apiKeyId,
        string $url,
        ?int $endpointId,
    ): ?ApiWebhookDelivery {
        try {
            WebhookUrlValidator::assertAllowed($url);
        } catch (InvalidArgumentException $e) {
            Log::warning('Api webhook URL blocked', [
                'api_application_id' => $app->id,
                'api_application_webhook_id' => $endpointId,
                'event' => $event,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $enriched = array_merge([
            'event' => $event,
            'created_at' => now()->toIso8601String(),
        ], $payload);

        return ApiWebhookDelivery::create([
            'tenant_id' => (int) $app->tenant_id,
            'api_application_id' => $app->id,
            'api_application_webhook_id' => $endpointId,
            'api_key_id' => $apiKeyId,
            'event' => $event,
            'payload' => $enriched,
            'url' => $url,
            'status' => ApiWebhookDelivery::STATUS_PENDING,
            'next_retry_at' => now(),
        ]);
    }

    /**
     * @return list<int>
     */
    public static function retryDelaysSeconds(): array
    {
        return [30, 120, 600, 3600, 21600, 86400];
    }

    public function scheduleRetry(ApiWebhookDelivery $delivery): void
    {
        $delays = self::retryDelaysSeconds();
        $attempt = (int) $delivery->attempt;

        if ($attempt >= count($delays)) {
            $delivery->update(['status' => ApiWebhookDelivery::STATUS_FAILED]);

            return;
        }

        $delivery->update([
            'status' => ApiWebhookDelivery::STATUS_PENDING,
            'next_retry_at' => now()->addSeconds($delays[$attempt]),
        ]);

        DeliverApiWebhookJob::dispatch($delivery->id)->delay($delays[$attempt]);
    }

    public function retryNow(ApiWebhookDelivery $delivery): ApiWebhookDelivery
    {
        $app = ApiApplication::query()->find($delivery->api_application_id);
        $endpoint = $delivery->api_application_webhook_id
            ? ApiApplicationWebhook::query()->find($delivery->api_application_webhook_id)
            : null;

        $url = is_string($endpoint?->url) && $endpoint->url !== ''
            ? (string) $endpoint->url
            : (is_string($app?->webhook_url) && $app->webhook_url !== ''
                ? (string) $app->webhook_url
                : (string) $delivery->url);

        $delivery->update([
            'status' => ApiWebhookDelivery::STATUS_PENDING,
            'attempt' => 0,
            'next_retry_at' => now(),
            'delivered_at' => null,
            'url' => $url,
        ]);

        DeliverApiWebhookJob::dispatch($delivery->id);

        return $delivery->fresh() ?? $delivery;
    }

    public function resolveSigningSecret(ApiWebhookDelivery $delivery, ?ApiApplication $app): ?string
    {
        if ($delivery->api_application_webhook_id) {
            $endpoint = ApiApplicationWebhook::query()->find($delivery->api_application_webhook_id);
            if (is_string($endpoint?->secret) && $endpoint->secret !== '') {
                return $endpoint->secret;
            }
        }

        $secret = $app?->webhook_secret;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
