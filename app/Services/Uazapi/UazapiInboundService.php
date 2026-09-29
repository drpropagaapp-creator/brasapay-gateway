<?php

namespace App\Services\Uazapi;

use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Models\UazapiOptOut;
use App\Models\UazapiRecoveryStop;
use Illuminate\Support\Facades\Log;

class UazapiInboundService
{
    public function __construct(private UazapiClient $client, private UazapiLabelService $labels) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(UazapiInstance $instance, array $payload): void
    {
        $eventType = strtolower((string) ($payload['EventType'] ?? $payload['event'] ?? ''));
        if ($eventType !== '' && ! in_array($eventType, ['messages', 'message'], true)) {
            return;
        }

        $message = $this->extractMessage($payload);
        if ($message === null || $this->isOutbound($message) || $this->isGroup($message)) {
            return;
        }

        $phone = $this->extractPhone($message);
        if ($phone === null) {
            return;
        }

        $text = $this->extractText($message);
        $intent = $this->classify($text);

        $this->persistStop($instance, $phone, $intent, $text);
        UazapiMessageDispatch::cancelPendingForPhone(
            (int) $instance->id,
            $phone,
            $this->cancelReason($intent)
        );

        if ($intent === UazapiRecoveryStop::REASON_OPT_OUT) {
            $this->persistOptOut($instance, $phone, $text);
            $this->maybeReply($instance, $phone, (string) config('uazapi.inbound.opt_out_reply', ''));
        } elseif ($intent === UazapiRecoveryStop::REASON_PAID) {
            $this->maybeReply($instance, $phone, (string) config('uazapi.inbound.paid_reply', ''));
            $this->labels->apply($instance, $phone, UazapiLabelService::PAID);
        } else {
            $this->labels->apply($instance, $phone, UazapiLabelService::HOT);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractMessage(array $payload): ?array
    {
        foreach (['message', 'data', 'messages'] as $key) {
            $candidate = $payload[$key] ?? null;
            if (! is_array($candidate) || $candidate === []) {
                continue;
            }

            if (array_is_list($candidate)) {
                $first = $candidate[0] ?? null;

                return is_array($first) ? $first : null;
            }

            return $candidate;
        }

        if (isset($payload['fromMe']) || isset($payload['chatid']) || isset($payload['sender']) || isset($payload['text'])) {
            return $payload;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isOutbound(array $message): bool
    {
        return $this->truthy($message['fromMe'] ?? false) || $this->truthy($message['wasSentByApi'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isGroup(array $message): bool
    {
        if ($this->truthy($message['isGroup'] ?? false)) {
            return true;
        }

        $chatId = strtolower((string) ($message['chatid'] ?? $message['chatId'] ?? ''));

        return str_ends_with($chatId, '@g.us');
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function extractPhone(array $message): ?string
    {
        $candidates = [
            $message['sender_pn'] ?? null,
            $message['senderPn'] ?? null,
            $message['sender'] ?? null,
            $message['chatid'] ?? null,
            $message['chatId'] ?? null,
            $message['owner'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $jid = explode(':', explode('@', $candidate)[0])[0];
            $phone = $this->client->normalizePhone($jid);
            if ($phone !== null) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function extractText(array $message): string
    {
        foreach (['text', 'content', 'conversation', 'buttonOrListid', 'buttonOrListId'] as $key) {
            $value = $message[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $nested = $message['message'] ?? null;
        if (is_array($nested)) {
            foreach (['conversation', 'text', 'content'] as $key) {
                $value = $nested[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    private function classify(string $text): string
    {
        $normalized = $this->normalizeIntentText($text);

        foreach ($this->keywords('opt_out_keywords') as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return UazapiRecoveryStop::REASON_OPT_OUT;
            }
        }

        foreach ($this->keywords('paid_keywords') as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return UazapiRecoveryStop::REASON_PAID;
            }
        }

        return UazapiRecoveryStop::REASON_REPLIED;
    }

    /**
     * @return list<string>
     */
    private function keywords(string $configKey): array
    {
        $raw = config('uazapi.inbound.'.$configKey, []);
        if (! is_array($raw)) {
            return [];
        }

        $keywords = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $normalized = $this->normalizeIntentText($item);
            if ($normalized !== '') {
                $keywords[] = $normalized;
            }
        }

        return $keywords;
    }

    private function normalizeIntentText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return '';
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $value = is_string($ascii) && $ascii !== '' ? strtolower($ascii) : $text;

        return (string) preg_replace('/\s+/', ' ', $value);
    }

    private function persistStop(UazapiInstance $instance, string $phone, string $reason, string $text): void
    {
        UazapiRecoveryStop::query()->create([
            'tenant_id' => (int) $instance->tenant_id,
            'uazapi_instance_id' => $instance->id,
            'phone' => $phone,
            'reason' => $reason,
            'inbound_text' => $text !== '' ? mb_substr($text, 0, 500) : null,
        ]);
    }

    private function persistOptOut(UazapiInstance $instance, string $phone, string $text): void
    {
        UazapiOptOut::query()->updateOrCreate(
            [
                'tenant_id' => (int) $instance->tenant_id,
                'phone' => $phone,
            ],
            [
                'uazapi_instance_id' => $instance->id,
                'source' => 'inbound',
                'inbound_text' => $text !== '' ? mb_substr($text, 0, 500) : null,
            ]
        );
    }

    private function maybeReply(UazapiInstance $instance, string $phone, string $message): void
    {
        $message = trim($message);
        $token = (string) ($instance->instance_token ?? '');
        if ($message === '' || $token === '') {
            return;
        }

        try {
            $this->client->using($instance)->sendText($token, [
                'number' => $phone,
                'text' => $message,
                'track_source' => 'stacker-recovery',
                'track_id' => 'uazapi-inbound-'.$instance->id.'-'.time(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('UazapiInboundService: falha ao responder inbound', [
                'instance_id' => $instance->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function cancelReason(string $intent): string
    {
        return match ($intent) {
            UazapiRecoveryStop::REASON_OPT_OUT => 'Lead pediu para parar os envios.',
            UazapiRecoveryStop::REASON_PAID => 'Lead informou que já pagou — sequência interrompida.',
            default => 'Lead respondeu no WhatsApp — sequência interrompida.',
        };
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }
}
