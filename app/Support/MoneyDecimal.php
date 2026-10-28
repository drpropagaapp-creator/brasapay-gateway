<?php

namespace App\Support;

/**
 * Valores monetários (BRL) com 2 casas decimais, sem drift de float.
 */
final class MoneyDecimal
{
    private const SCALE = 8;

    public static function normalize(mixed $input): string
    {
        if ($input === null || $input === '') {
            return '0.00';
        }

        if (is_string($input)) {
            $s = trim(str_replace([' ', ','], ['', '.'], $input));
            if ($s === '' || ! is_numeric($s)) {
                return '0.00';
            }
            $input = $s;
        }

        if (! is_int($input) && ! is_float($input) && ! is_string($input)) {
            return '0.00';
        }

        $negative = is_string($input)
            ? str_starts_with(trim($input), '-')
            : ((float) $input) < 0;

        $abs = is_string($input) ? ltrim(trim($input), '-+') : (string) abs((float) $input);

        $centsRounded = bcadd(bcmul($abs, '100', 4), '0.5', 0);
        $result = bcdiv($centsRounded, '100', 2);

        return $negative ? '-'.$result : $result;
    }

    public static function toFloat(mixed $input): float
    {
        return (float) self::normalize($input);
    }

    /** Valor em centavos inteiros (arredondamento half-up nos centavos). */
    public static function toCents(mixed $input): int
    {
        return (int) bcmul(self::normalize($input), '100', 0);
    }

    public static function fromCents(int $cents): float
    {
        return (float) bcdiv((string) $cents, '100', 2);
    }

    /**
     * Percentual com até 2 casas em pontos-base inteiros (20.5% → 2050).
     * Contabilidade de split usa só inteiros: nada de float na conta.
     */
    public static function percentToBasisPoints(mixed $percent): int
    {
        return self::toCents($percent);
    }

    /**
     * Converte preço armazenado (na moeda do produto) para BRL para exibição/edição.
     */
    public static function brlFromStorage(float $storedPrice, string $currency, array $rates): float
    {
        $currency = strtoupper(trim($currency ?: 'BRL'));
        $stored = self::normalize($storedPrice);

        if ($currency === 'BRL') {
            return self::toFloat($stored);
        }

        if ($currency === 'EUR') {
            $rate = self::normalizeRate($rates['brl_eur'] ?? 0.16);

            return self::toFloat(bcdiv($stored, $rate, self::SCALE));
        }

        $rate = self::normalizeRate($rates['brl_usd'] ?? 0.18);

        return self::toFloat(bcdiv($stored, $rate, self::SCALE));
    }

    /**
     * Converte preço informado em BRL para a moeda de armazenamento do produto.
     */
    public static function storageFromBrl(float $priceBrl, string $currency, array $rates): float
    {
        $currency = strtoupper(trim($currency ?: 'BRL'));
        $brl = self::normalize($priceBrl);

        if ($currency === 'BRL') {
            return self::toFloat($brl);
        }

        if ($currency === 'EUR') {
            $rate = self::normalizeRate($rates['brl_eur'] ?? 0.16);

            return self::toFloat(bcmul($brl, $rate, self::SCALE));
        }

        $rate = self::normalizeRate($rates['brl_usd'] ?? 0.18);

        return self::toFloat(bcmul($brl, $rate, self::SCALE));
    }

    private static function normalizeRate(mixed $rate): string
    {
        $r = self::normalize($rate);
        if (bccomp($r, '0', self::SCALE) <= 0) {
            return '0.01';
        }

        return $r;
    }
}
