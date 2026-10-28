<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Configuração global do Brasa AI (só o admin da plataforma edita). A chave do provedor fica
 * criptografada e nunca volta para o navegador.
 */
final class AiAssistantSettings
{
    private const KEY = 'ai_assistant';

    private const SECRET_KEY = 'ai_assistant_api_key';

    /** Modelos aceitos e preço por milhão de tokens (US$) para estimar custo. */
    public const MODELS = [
        'claude-opus-5-5' => ['label' => 'Claude Opus 5.5', 'input' => 4.00, 'output' => 20.00],
        'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5', 'input' => 2.00, 'output' => 10.00],
        'claude-haiku-5-5' => ['label' => 'Claude Haiku 5.5', 'input' => 0.10, 'output' => 0.50],
    ];

    public const EFFORTS = ['low', 'medium', 'high'];

    /** @return array<string, mixed> */
    public static function get(): array
    {
        $raw = Setting::get(self::KEY, null);
        $raw = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'provider' => 'anthropic',
            'model' => array_key_exists($raw['model'] ?? '', self::MODELS) ? $raw['model'] : 'claude-opus-5-5',
            'effort' => in_array($raw['effort'] ?? '', self::EFFORTS, true) ? $raw['effort'] : 'medium',
            'daily_messages_per_user' => max(1, min(500, (int) ($raw['daily_messages_per_user'] ?? 30))),
            'monthly_budget_cents_per_tenant' => max(0, min(1_000_000, (int) ($raw['monthly_budget_cents_per_tenant'] ?? 500))), // US$ 5,00
            'monthly_budget_cents_platform' => max(0, min(100_000_000, (int) ($raw['monthly_budget_cents_platform'] ?? 20_000))), // US$ 200,00
        ];
    }

    public static function save(array $input): array
    {
        $current = self::get();
        $next = array_merge($current, array_intersect_key($input, $current));
        unset($next['provider']);
        Setting::set(self::KEY, json_encode($next));

        return self::get();
    }

    public static function apiKey(): ?string
    {
        $enc = Setting::get(self::SECRET_KEY, null);
        if (! is_string($enc) || $enc === '') {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function setApiKey(?string $key): void
    {
        Setting::set(self::SECRET_KEY, $key ? Crypt::encryptString($key) : '');
    }

    public static function isConfigured(): bool
    {
        return self::get()['enabled'] && self::apiKey() !== null;
    }

    /** Custo estimado em micro-dólares (inteiro) para o modelo que de fato respondeu. */
    public static function costMicros(string $model, int $inputTokens, int $outputTokens): int
    {
        $price = self::MODELS[$model] ?? self::MODELS['claude-opus-5-5'];

        return (int) round($inputTokens * $price['input'] + $outputTokens * $price['output']);
    }
}
