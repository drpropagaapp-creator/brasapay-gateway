<?php

namespace App\Jobs;

use App\Models\PlatformWhatsappChannel;
use App\Services\PlatformWhatsapp\PlatformWhatsappChannelService;
use App\Services\PlatformWhatsapp\PlatformWhatsappInboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPlatformWhatsappWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $channelId,
        public array $payload,
    ) {
        $this->onQueue((string) config('platform_whatsapp.queue', 'uazapi'));
    }

    public function handle(
        PlatformWhatsappChannelService $channelService,
        PlatformWhatsappInboundService $inboundService
    ): void {
        $channel = PlatformWhatsappChannel::query()->find($this->channelId);
        if (! $channel) {
            return;
        }

        $raw = (string) ($this->payload['event'] ?? $this->payload['EventType'] ?? '');
        $event = strtoupper($raw);

        $isConnection = in_array($event, ['CONNECTION_UPDATE', 'QRCODE_UPDATED', 'CONNECTION'], true);
        $isMessage = in_array($event, ['MESSAGES_UPSERT', 'MESSAGES.UPSERT', 'MESSAGES', 'MESSAGE'], true);

        if ($isConnection) {
            $channelService->applyWebhookPayload($channel, $this->payload);
        }

        if ($isMessage) {
            $inboundService->handle($channel, $this->payload);
        }
    }
}
