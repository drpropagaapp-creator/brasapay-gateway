<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Configuração do Brasa Recovery por e-mail (por seller). Desligado por padrão.
 * Placeholders: {nome} {produto} {valor} {link}. O link de descadastro é sempre incluído.
 */
class RecoveryEmailSettings
{
    public const EVENTS = ['cart_abandoned', 'pix_pending', 'boleto_pending'];

    public const KEY = 'recovery_email';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'quiet_start' => 22,
            'quiet_end' => 8,
            'max_per_day' => 2,
            'events' => [
                'cart_abandoned' => ['enabled' => true, 'steps' => [
                    ['delay_minutes' => 30, 'subject' => '{nome}, ficou faltando pouco', 'body' => "Oi {nome}!\n\nVocê começou a compra de {produto} e não finalizou. Se ainda quiser, é só continuar por aqui:\n\n{link}"],
                    ['delay_minutes' => 1440, 'subject' => 'Ainda dá tempo: {produto}', 'body' => "Oi {nome}, passando para lembrar que {produto} continua esperando por você.\n\n{link}"],
                ]],
                'pix_pending' => ['enabled' => true, 'steps' => [
                    ['delay_minutes' => 20, 'subject' => 'Seu PIX de {valor} ainda está aberto', 'body' => "Oi {nome}, o PIX da sua compra de {produto} ainda não foi pago. Se preferir, gere um novo pagamento aqui:\n\n{link}"],
                ]],
                'boleto_pending' => ['enabled' => true, 'steps' => [
                    ['delay_minutes' => 1440, 'subject' => 'Lembrete: boleto de {produto}', 'body' => "Oi {nome}, o boleto de {valor} da sua compra de {produto} ainda está em aberto. Se precisar, refaça a compra por aqui:\n\n{link}"],
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function forTenant(int $tenantId): array
    {
        $raw = Setting::get(self::KEY, null, $tenantId);
        $raw = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);

        return self::normalize(array_replace_recursive(self::defaults(), $raw));
    }

    /** @param array<string, mixed> $input */
    public static function normalize(array $input): array
    {
        $d = self::defaults();
        $out = [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'quiet_start' => max(0, min(23, (int) ($input['quiet_start'] ?? $d['quiet_start']))),
            'quiet_end' => max(0, min(23, (int) ($input['quiet_end'] ?? $d['quiet_end']))),
            'max_per_day' => max(1, min(5, (int) ($input['max_per_day'] ?? $d['max_per_day']))),
            'events' => [],
        ];
        foreach (self::EVENTS as $event) {
            $e = $input['events'][$event] ?? $d['events'][$event];
            $steps = [];
            foreach (array_slice(is_array($e['steps'] ?? null) ? $e['steps'] : [], 0, 3) as $s) {
                $subject = trim(strip_tags((string) ($s['subject'] ?? '')));
                $body = trim(strip_tags((string) ($s['body'] ?? '')));
                if ($subject === '' || $body === '') {
                    continue;
                }
                $steps[] = [
                    'delay_minutes' => max(10, min(10080, (int) ($s['delay_minutes'] ?? 60))),
                    'subject' => mb_substr($subject, 0, 150),
                    'body' => mb_substr($body, 0, 3000),
                ];
            }
            usort($steps, fn ($a, $b) => $a['delay_minutes'] <=> $b['delay_minutes']);
            $out['events'][$event] = ['enabled' => (bool) ($e['enabled'] ?? false), 'steps' => $steps];
        }

        return $out;
    }

    public static function save(int $tenantId, array $input): array
    {
        $normalized = self::normalize($input);
        Setting::set(self::KEY, json_encode($normalized), $tenantId);

        return $normalized;
    }
}
