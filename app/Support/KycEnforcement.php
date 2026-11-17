<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Quando o KYC trava a operação do infoprodutor.
 * onboarding: padrão — vender e sacar exigem KYC aprovado.
 * withdrawal: vender exige só a aprovação básica da conta; o KYC trava o saque.
 */
final class KycEnforcement
{
    public const KEY = 'kyc_enforcement';

    public const ONBOARDING = 'onboarding';

    public const WITHDRAWAL = 'withdrawal';

    public static function mode(): string
    {
        $raw = strtolower(trim((string) Setting::get(self::KEY, self::ONBOARDING, null)));

        return $raw === self::WITHDRAWAL ? self::WITHDRAWAL : self::ONBOARDING;
    }

    public static function requiresKycBeforeSelling(): bool
    {
        return self::mode() !== self::WITHDRAWAL;
    }

    /**
     * @return array{kyc_enforcement: string}
     */
    public static function forSettingsForm(): array
    {
        return [
            'kyc_enforcement' => self::mode(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function persistFromValidated(array $validated): void
    {
        if (! array_key_exists('kyc_enforcement', $validated)) {
            return;
        }

        $mode = $validated['kyc_enforcement'] === self::WITHDRAWAL
            ? self::WITHDRAWAL
            : self::ONBOARDING;

        Setting::set(self::KEY, $mode, null);
    }
}
