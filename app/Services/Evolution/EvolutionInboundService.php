<?php

namespace App\Services\Evolution;

use App\Models\EvolutionInstance;
use App\Models\EvolutionMessageDispatch;
use App\Models\EvolutionOptOut;
use App\Models\EvolutionRecoveryStop;
use Illuminate\Support\Facades\Log;

class EvolutionInboundService
{
    public function __construct(private EvolutionClient $client) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(EvolutionInstance $instance, array $payload): void
    {
        $message = $this->extractMessage($payload);
        if ($message === null || $this->isOutbound($message) || $this->isGroup($message)) {
            return;
        }

        $phone = $this->extractPhone($message, $payload);
        if ($phone === null) {
            return;
        }

        $text = $this->extractText($message);
        $intent = $this->classify($text);

        EvolutionRecoveryStop::query()->create([
            'tenant_id' => (int) $instance->tenant_id,
            'evolution_instance_id' => $instance->id,
            'phone' => $phone,
            'reason' => $intent,
            'inbound_text' => $text !== '' ? mb_substr($text, 0, 500) : null,
        ]);

        EvolutionMessageDispatch::cancelPendingForPhone(
            (int) $instance->id,
            $phone,
            $this->cancelReason($intent)
        );

        if ($intent === EvolutionRecoveryStop::REASON_OPT_OUT) {
            EvolutionOptOut::query()->updateOrCreate(
                [
                    'tenant_id' => (int) $instance->tenant_id,
                    'phone' => $phone,
                ],
                [
                    'evolution_instance_id' => $instance->id,
                    'source' => 'inbound',
                    'inbound_text' => $text !== '' ? mb_substr($text, 0, 500) : null,
                ]
            );
            $this->maybeReply($instance, $phone, (string) config('evolution.inbound.opt_out_reply', ''));
        } elseif ($intent === EvolutionRecoveryStop::REASON_PAID) {
            $this->maybeReply($instance, $phone, (string) config('evolution.inbound.paid_reply', ''));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractMessage(array $payload): ?array
    {
        $data = $payload['data'] ?? $payload['message'] ?? null;
        if (is_array($data)) {
            if (array_is_list($data)) {
                $first = $data[0] ?? null;

                return is_array($first) ? $first : null;
            }

            return $data;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isOutbound(array $message): bool
    {
        $key = is_array($message['key'] ?? null) ? $message['key'] : [];

        return $this->truthy($key['fromMe'] ?? $message['fromMe'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isGroup(array $message): bool
    {
        $key = is_array($message['key'] ?? null) ? $message['key'] : [];
        $jid = strtolower((string) ($key['remoteJid'] ?? $message['remoteJid'] ?? ''));

        return str_ends_with($jid, '@g.us');
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $payload
     */
    private function extractPhone(array $message, array $payload): ?string
    {
        $key = is_array($message['key'] ?? null) ? $message['key'] : [];
        $candidates = [
            $key['remoteJid'] ?? null,
            $message['remoteJid'] ?? null,
            $payload['sender'] ?? null,
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
        $nested = $message['message'] ?? null;
        if (is_array($nested)) {
            foreach (['conversation', 'text'] as $key) {
                $value = $nested[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
            $extended = $nested['extendedTextMessage']['text'] ?? null;
            if (is_string($extended) && trim($extended) !== '') {
                return trim($extended);
            }
        }

        foreach (['conversation', 'text'] as $key) {
            $value = $message[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function classify(string $text): string
    {
        $normalized = $this->normalizeIntentText($text);

        foreach ($this->keywords('opt_out_keywords') as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return EvolutionRecoveryStop::REASON_OPT_OUT;
            }
        }

        foreach ($this->keywords('paid_keywords') as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return EvolutionRecoveryStop::REASON_PAID;
            }
        }

        return EvolutionRecoveryStop::REASON_REPLIED;
    }

    /**
     * @return list<string>
     */
    private function keywords(string $configKey): array
    {
        $raw = config('evolution.inbound.'.$configKey, []);
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

    private function maybeReply(EvolutionInstance $instance, string $phone, string $message): void
    {
        $message = trim($message);
        if ($message === '' || ! $instance->hasCredentials()) {
            return;
        }

        try {
            $this->client->using($instance)->sendText(
                (string) $instance->instance_token,
                (string) $instance->instance_name,
                $phone,
                $message
            );
        } catch (\Throwable $e) {
            Log::warning('EvolutionInboundService: falha ao responder inbound', [
                'instance_id' => $instance->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function cancelReason(string $intent): string
    {
        return match ($intent) {
            EvolutionRecoveryStop::REASON_OPT_OUT => 'Lead pediu para parar os envios.',
            EvolutionRecoveryStop::REASON_PAID => 'Lead informou que já pagou — sequência interrompida.',
            default => 'Lead respondeu no WhatsApp — sequência interrompida.',
        };
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 'true', 'TRUE'], true);
    }
}
