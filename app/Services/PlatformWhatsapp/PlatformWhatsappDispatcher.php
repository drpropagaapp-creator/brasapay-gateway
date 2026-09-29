<?php

namespace App\Services\PlatformWhatsapp;

use App\Jobs\PlatformWhatsappSendJob;
use App\Models\PlatformWhatsappChannel;
use App\Models\PlatformWhatsappDispatch;
use App\Models\PlatformWhatsappOptOut;
use App\Models\PlatformWhatsappTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PlatformWhatsappDispatcher
{
    public function __construct(private PlatformWhatsappMessageVars $vars) {}

    /**
     * @param  array<string, string>  $extra
     */
    public function notify(string $eventKey, User $seller, array $extra = []): bool
    {
        $channel = PlatformWhatsappChannel::current();
        if (! $channel->canSend()) {
            return false;
        }

        $template = PlatformWhatsappTemplate::findEnabled($eventKey);
        if (! $template) {
            return false;
        }

        $phone = $this->vars->phoneOf($seller);
        if ($phone === null) {
            Log::info('PlatformWhatsappDispatcher: sem telefone', [
                'event' => $eventKey,
                'user_id' => $seller->id,
            ]);

            return false;
        }

        if (PlatformWhatsappOptOut::isOptedOut($phone)) {
            return false;
        }

        $message = $this->vars->render($template->message, $this->vars->forSeller($seller, $extra));
        $this->assertLength($message);

        return $this->queue($eventKey, $phone, $message, (int) $seller->id);
    }

    public function queue(
        string $eventType,
        string $phone,
        string $message,
        ?int $userId = null,
        ?int $campaignId = null,
        int $delaySeconds = 0
    ): bool {
        $dispatch = PlatformWhatsappDispatch::query()->create([
            'user_id' => $userId,
            'campaign_id' => $campaignId,
            'event_type' => $eventType,
            'phone' => $phone,
            'message' => $message,
            'status' => PlatformWhatsappDispatch::STATUS_PENDING,
        ]);
        $dispatch->track_id = 'platform-wa-'.$dispatch->id;
        $dispatch->save();

        $job = PlatformWhatsappSendJob::dispatch($dispatch->id)
            ->onQueue((string) config('platform_whatsapp.queue', 'uazapi'));
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }

        return true;
    }

    private function assertLength(string $message): void
    {
        $max = (int) config('platform_whatsapp.max_message_length', 1000);
        if (mb_strlen($message) > $max) {
            throw new \InvalidArgumentException(
                'Mensagem WhatsApp excede '.$max.' caracteres ('.mb_strlen($message).' após substituição).'
            );
        }
    }
}
