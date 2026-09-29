<?php

namespace App\Jobs;

use App\Models\PlatformWhatsappChannel;
use App\Models\PlatformWhatsappDispatch;
use App\Models\PlatformWhatsappOptOut;
use App\Services\PlatformWhatsapp\PlatformWhatsappChannelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PlatformWhatsappSendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public int $dispatchId)
    {
        $this->tries = (int) config('platform_whatsapp.retry.tries', 3);
        $this->timeout = (int) config('platform_whatsapp.retry.timeout', 60);
        $this->onQueue((string) config('platform_whatsapp.queue', 'uazapi'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('platform_whatsapp.retry.backoff', [30, 90]);

        return is_array($backoff) ? array_map('intval', $backoff) : [30, 90];
    }

    public function handle(PlatformWhatsappChannelService $channelService): void
    {
        $dispatch = PlatformWhatsappDispatch::query()->find($this->dispatchId);
        if (! $dispatch || $dispatch->status !== PlatformWhatsappDispatch::STATUS_PENDING) {
            return;
        }

        if (PlatformWhatsappOptOut::isOptedOut($dispatch->phone)) {
            $dispatch->update([
                'status' => PlatformWhatsappDispatch::STATUS_CANCELED,
                'error' => 'Número pediu para parar os envios.',
            ]);

            return;
        }

        $channel = PlatformWhatsappChannel::current();
        if (! $channel->canSend()) {
            $dispatch->update([
                'status' => PlatformWhatsappDispatch::STATUS_FAILED,
                'error' => 'Canal WhatsApp da plataforma desconectado ou inativo.',
            ]);

            return;
        }

        try {
            $channelService->sendText($channel, $dispatch->phone, $dispatch->message);
            $dispatch->update([
                'status' => PlatformWhatsappDispatch::STATUS_SENT,
                'wa_status' => 'Sent',
                'sent_at' => now(),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $retryable = str_contains(strtolower($e->getMessage()), '429')
                || str_contains(strtolower($e->getMessage()), '503');
            $dispatch->update([
                'status' => $retryable ? PlatformWhatsappDispatch::STATUS_PENDING : PlatformWhatsappDispatch::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('PlatformWhatsappSendJob failed', [
                'dispatch_id' => $dispatch->id,
                'message' => $e->getMessage(),
            ]);

            if ($retryable) {
                throw $e;
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $dispatch = PlatformWhatsappDispatch::query()->find($this->dispatchId);
        if (! $dispatch || $dispatch->status !== PlatformWhatsappDispatch::STATUS_PENDING) {
            return;
        }

        $dispatch->update([
            'status' => PlatformWhatsappDispatch::STATUS_FAILED,
            'error' => $exception !== null ? mb_substr($exception->getMessage(), 0, 500) : null,
        ]);
    }
}
