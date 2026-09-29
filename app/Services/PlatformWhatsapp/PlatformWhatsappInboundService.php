<?php

namespace App\Services\PlatformWhatsapp;

use App\Models\PlatformWhatsappChannel;
use App\Models\PlatformWhatsappDispatch;
use App\Models\PlatformWhatsappOptOut;
use App\Models\User;
use App\Services\Evolution\EvolutionClient;
use App\Services\Uazapi\UazapiClient;
use Illuminate\Support\Facades\Log;

class PlatformWhatsappInboundService
{
    public function __construct(
        private UazapiClient $uazapi,
        private EvolutionClient $evolution,
        private PlatformWhatsappChannelService $channelService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(PlatformWhatsappChannel $channel, array $payload): void
    {
        $message = $this->extractMessage($payload);
        if ($message === null || $this->isOutbound($message) || $this->isGroup($message)) {
            return;
        }

        $phone = $this->extractPhone($channel, $message, $payload);
        if ($phone === null) {
            return;
        }

        $text = $this->extractText($message);
        if (! $this->isOptOut($text)) {
            return;
        }

        $userId = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where('phone', $phone)
            ->value('id');

        PlatformWhatsappOptOut::query()->updateOrCreate(
            ['phone' => $phone],
            [
                'user_id' => $userId !== null ? (int) $userId : null,
                'source' => 'inbound',
                'inbound_text' => $text !== '' ? mb_substr($text, 0, 500) : null,
            ]
        );

        PlatformWhatsappDispatch::cancelPendingForPhone($phone, 'Lead pediu para parar os envios.');

        $reply = trim((string) config('platform_whatsapp.inbound.opt_out_reply', ''));
        if ($reply === '' || ! $channel->canSend()) {
            return;
        }

        try {
            $this->channelService->sendText($channel, $phone, $reply);
        } catch (\Throwable $e) {
            Log::warning('PlatformWhatsappInboundService: falha ao responder opt-out', [
                'message' => $e->getMessage(),
            ]);
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

        if (isset($payload['fromMe']) || isset($payload['chatid']) || isset($payload['text'])) {
            return $payload;
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
        $jid = strtolower((string) ($key['remoteJid'] ?? $message['remoteJid'] ?? $message['chatid'] ?? ''));

        return str_contains($jid, '@g.us');
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $payload
     */
    private function extractPhone(PlatformWhatsappChannel $channel, array $message, array $payload): ?string
    {
        $key = is_array($message['key'] ?? null) ? $message['key'] : [];
        $candidates = [
            $key['remoteJid'] ?? null,
            $message['remoteJid'] ?? null,
            $message['chatid'] ?? null,
            $message['sender'] ?? null,
            $payload['sender'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $jid = explode(':', explode('@', $candidate)[0])[0];
            $phone = $channel->isEvolution()
                ? $this->evolution->normalizePhone($jid)
                : $this->uazapi->normalizePhone($jid);
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

    private function isOptOut(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        $value = is_string($ascii) && $ascii !== '' ? strtolower($ascii) : $normalized;
        $value = (string) preg_replace('/\s+/', ' ', $value);

        $raw = config('platform_whatsapp.inbound.opt_out_keywords', []);
        if (! is_array($raw)) {
            return false;
        }

        foreach ($raw as $keyword) {
            if (! is_string($keyword) || $keyword === '') {
                continue;
            }
            $needle = mb_strtolower(trim($keyword));
            $needleAscii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $needle);
            $needle = is_string($needleAscii) && $needleAscii !== '' ? strtolower($needleAscii) : $needle;
            if ($needle !== '' && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 'true', 'TRUE'], true);
    }
}
