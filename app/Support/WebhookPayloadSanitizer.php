<?php

namespace App\Support;

/**
 * Mascara dados pessoais de um payload de webhook antes de exibi-lo no painel do desenvolvedor.
 * Mantém a estrutura (útil para depurar) e esconde documento, telefone, chave Pix e parte do e-mail.
 */
final class WebhookPayloadSanitizer
{
    private const MASK_KEYS = ['cpf', 'cnpj', 'document', 'doc', 'phone', 'telefone', 'pix_key', 'key_owner_document', 'card_mask', 'payment_token', 'secret', 'token', 'password'];

    public static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::sanitize($v, is_string($k) ? strtolower($k) : null);
            }

            return $out;
        }
        if (! is_string($value) || $value === '' || $key === null) {
            return $value;
        }
        if (in_array($key, self::MASK_KEYS, true)) {
            return mb_strlen($value) <= 4 ? '****' : str_repeat('*', max(4, mb_strlen($value) - 4)).mb_substr($value, -2);
        }
        if (str_contains($key, 'email') && str_contains($value, '@')) {
            [$user, $domain] = explode('@', $value, 2);

            return mb_substr($user, 0, 1).'***@'.$domain;
        }

        return $value;
    }
}
