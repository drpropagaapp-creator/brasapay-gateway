<?php

namespace App\Support;

/**
 * E.164 (só dígitos, sem "+") para Advanced Matching e CAPI.
 * Número incompleto ou com tamanho inválido para o país → null (não enviar ph).
 *
 * Deve permanecer alinhado com normalizeMetaPhone() em resources/js/lib/metaTracking/browserPixel.js.
 */
final class MetaPhoneNormalizer
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

        if ($digits === '') {
            return null;
        }

        return self::fromDigits($digits, $explicitIntl);
    }

    /**
     * @return non-empty-string|null
     */
    private static function fromDigits(string $digits, bool $explicitIntl): ?string
    {
        $len = strlen($digits);
        $third = $digits[2] ?? '';
        $second = $digits[1] ?? '';

        // BR completo: 55 + fixo (10) ou móvel (11).
        if (str_starts_with($digits, '55') && ($len === 12 || $len === 13)) {
            return $digits;
        }

        // Com + / 00, não inventar DDI 55. Só aceita E.164 de 10–15, BR só 12–13.
        if ($explicitIntl) {
            if (str_starts_with($digits, '55')) {
                return null;
            }
            if ($len >= 10 && $len <= 15) {
                return $digits;
            }

            return null;
        }

        // BR móvel local: DDD + 9 + 8 dígitos.
        if ($len === 11 && ! str_starts_with($digits, '55') && $third === '9') {
            return '55'.$digits;
        }

        // BR fixo local: DDD + 8 dígitos (assinante 2–8).
        if ($len === 10 && ! str_starts_with($digits, '55') && $third >= '2' && $third <= '8') {
            return '55'.$digits;
        }

        // NANP (EUA/CA): 1 + área (2–9) + 7 dígitos.
        if ($len === 11 && str_starts_with($digits, '1') && $second >= '2' && $second <= '9') {
            return $digits;
        }

        // Internacional já com DDI (12–15), exceto 55 com tamanho inválido.
        if ($len >= 12 && $len <= 15 && ! str_starts_with($digits, '55')) {
            return $digits;
        }

        return null;
    }
}
