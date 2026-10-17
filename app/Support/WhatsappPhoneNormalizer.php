<?php

namespace App\Support;

/**
 * Normaliza WhatsApp para dígitos E.164 (sem "+").
 * Sem DDI explícito, assume Brasil (55) para 10–11 dígitos — UX atual.
 */
final class WhatsappPhoneNormalizer
{
    /**
     * @return non-empty-string|null
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $explicitIntl = str_starts_with($trimmed, '+')
            || (bool) preg_match('/^\s*00\d/', $trimmed);

        $digits = preg_replace('/\D+/', '', $trimmed);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $explicitIntl = true;
        }

        $len = strlen($digits);
        if ($len < 8 || $len > 15) {
            return null;
        }

        // +DDI / 00DDI: mantém o E.164 informado.
        if ($explicitIntl) {
            return $digits;
        }

        // Brasil com DDI.
        if (str_starts_with($digits, '55') && $len >= 12 && $len <= 13) {
            return $digits;
        }

        // Local BR (DDD + número) sem DDI → prefixa 55.
        if ($len >= 10 && $len <= 11) {
            return '55'.$digits;
        }

        // Já veio com DDI internacional (12–15 dígitos, fora do padrão BR 55+12/13).
        if ($len >= 12 && $len <= 15) {
            return $digits;
        }

        return null;
    }
}
