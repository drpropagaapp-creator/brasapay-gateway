<?php

namespace App\Jobs;

use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Services\Uazapi\UazapiInboundService;
use App\Services\Uazapi\UazapiInstanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessUazapiWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $instanceId,
        public array $payload,
    ) {
        $this->onQueue((string) config('uazapi.queue', 'uazapi'));
    }

    public function handle(UazapiInstanceService $instanceService, UazapiInboundService $inboundService): void
    {
        $instance = UazapiInstance::query()->find($this->instanceId);
        if (! $instance) {
            return;
        }

        $eventType = strtolower((string) ($this->payload['EventType'] ?? $this->payload['event'] ?? ''));

        if ($eventType === 'connection') {
            $instanceService->applyWebhookPayload($instance, $this->payload);

            return;
        }

        if ($eventType === 'messages_update') {
            $this->applyDeliveryUpdate($this->payload);

            return;
        }

        if (in_array($eventType, ['messages', 'message', ''], true)) {
            $inboundService->handle($instance, $this->payload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyDeliveryUpdate(array $payload): void
    {
        $message = $payload['message'] ?? $payload['data'] ?? $payload;
        if (! is_array($message)) {
            return;
        }

        $trackId = isset($message['track_id']) ? (string) $message['track_id'] : null;
        $providerId = isset($message['id']) ? (string) $message['id'] : (isset($message['messageid']) ? (string) $message['messageid'] : null);
        $status = isset($message['status']) ? (string) $message['status'] : null;

        if ($status === null || ($trackId === null && $providerId === null)) {
            return;
        }

        $query = UazapiMessageDispatch::query()->where('uazapi_instance_id', $this->instanceId);
        $query->where(function ($inner) use ($trackId, $providerId) {
            if ($trackId) {
                $inner->orWhere('track_id', $trackId);
            }
            if ($providerId) {
                $inner->orWhere('provider_message_id', $providerId);
            }
        });

        $dispatch = $query->orderByDesc('id')->first();
        if (! $dispatch) {
            return;
        }

        $normalized = $this->normalizeWaStatus($status);
        if ($normalized === null) {
            return;
        }

        $current = strtolower((string) ($dispatch->wa_status ?? ''));
        $rank = ['queued' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'played' => 5];
        if (isset($rank[$current], $rank[strtolower($normalized)]) && $rank[strtolower($normalized)] < $rank[$current]) {
            return;
        }

        $dispatch->wa_status = $normalized;
        if ($providerId && ! $dispatch->provider_message_id) {
            $dispatch->provider_message_id = $providerId;
        }
        $dispatch->save();
    }

    private function normalizeWaStatus(string $status): ?string
    {
        $value = strtolower(trim($status));

        return match ($value) {
            'queued' => 'Queued',
            'sent' => 'Sent',
            'delivered' => 'Delivered',
            'read' => 'Read',
            'played' => 'Played',
            'failed' => 'Failed',
            'expired' => 'Expired',
            'canceled', 'cancelled' => 'Canceled',
            default => null,
        };
    }
}
