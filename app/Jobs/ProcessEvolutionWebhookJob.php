<?php

namespace App\Jobs;

use App\Models\EvolutionInstance;
use App\Services\Evolution\EvolutionInboundService;
use App\Services\Evolution\EvolutionInstanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessEvolutionWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $instanceId,
        public array $payload,
    ) {
        $this->onQueue((string) config('evolution.queue', 'uazapi'));
    }

    public function handle(EvolutionInstanceService $instanceService, EvolutionInboundService $inboundService): void
    {
        $instance = EvolutionInstance::query()->find($this->instanceId);
        if (! $instance) {
            return;
        }

        $event = strtoupper((string) ($this->payload['event'] ?? $this->payload['EventType'] ?? ''));

        if (in_array($event, ['CONNECTION_UPDATE', 'QRCODE_UPDATED', ''], true) && $event !== '') {
            $instanceService->applyWebhookPayload($instance, $this->payload);

            if ($event !== 'QRCODE_UPDATED') {
                return;
            }
        }

        if (in_array($event, ['MESSAGES_UPSERT', 'MESSAGES.UPSERT'], true)) {
            $inboundService->handle($instance, $this->payload);
        }
    }
}
