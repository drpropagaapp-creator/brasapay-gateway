<?php

namespace App\Support;

use InvalidArgumentException;

class UazapiCartRecoverySteps
{
    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function defaults(): array
    {
        $message = (string) (config('uazapi.defaults.messages.cart_recovery') ?? 'Oi {nome}! Seu {produto} ainda está disponível. Finalize aqui: {link}');

        return [
            ['delay_minutes' => 10, 'message' => $message],
            ['delay_minutes' => 1440, 'message' => $message],
            ['delay_minutes' => 2880, 'message' => $message],
        ];
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function forInstance(?object $instance): array
    {
        $raw = $instance?->cart_recovery_steps;
        if (is_array($raw) && $raw !== []) {
            return self::normalizeStored($raw);
        }

        return self::defaults();
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function pixDefaults(): array
    {
        $message = (string) (config('uazapi.defaults.messages.pix_reminder')
            ?? config('uazapi.defaults.messages.pix_generated')
            ?? '{nome}, ainda dá tempo de pagar o PIX de {valor} para {produto}: {link}');

        return [
            ['delay_minutes' => 30, 'message' => $message],
            ['delay_minutes' => 120, 'message' => $message],
        ];
    }

    /**
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function forPixInstance(?object $instance): array
    {
        if ($instance === null || $instance->pix_recovery_steps === null) {
            return self::pixDefaults();
        }

        $raw = $instance->pix_recovery_steps;
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        return self::normalizeStored($raw);
    }

    /**
     * @return array<int, array{delay_value: int, delay_unit: string, message: string}>
     */
    public static function toUiPixSteps(?object $instance): array
    {
        return array_map(
            fn (array $step) => self::toUiStep($step),
            self::forPixInstance($instance)
        );
    }

    /**
     * @return array<int, array{delay_value: int, delay_unit: string, message: string}>
     */
    public static function toUiSteps(?object $instance): array
    {
        return array_map(
            fn (array $step) => self::toUiStep($step),
            self::forInstance($instance)
        );
    }

    /**
     * @param  array<int, array{delay_value?: mixed, delay_unit?: mixed, message?: mixed}>  $uiSteps
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function fromUiInput(array $uiSteps): array
    {
        $steps = [];

        foreach ($uiSteps as $uiStep) {
            if (! is_array($uiStep)) {
                continue;
            }

            $message = trim((string) ($uiStep['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $value = max(1, (int) ($uiStep['delay_value'] ?? 1));
            $unit = (string) ($uiStep['delay_unit'] ?? 'minutes');
            $delayMinutes = self::toDelayMinutes($value, $unit);

            if ($delayMinutes > self::maxDelayMinutes()) {
                throw new InvalidArgumentException('Cada mensagem deve ser agendada em no máximo '.self::maxDelayMinutesLabel().'.');
            }

            $steps[] = [
                'delay_minutes' => $delayMinutes,
                'message' => $message,
            ];
        }

        return self::normalizeStored($steps);
    }

    /**
     * @param  array<int, array{delay_minutes?: mixed, message?: mixed}>  $steps
     * @return array<int, array{delay_minutes: int, message: string}>
     */
    public static function normalizeStored(array $steps): array
    {
        $normalized = [];
        $maxLen = (int) config('uazapi.max_message_length', 1000);

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $message = trim((string) ($step['message'] ?? ''));
            $delayMinutes = max(1, (int) ($step['delay_minutes'] ?? 0));

            if ($message === '' || $delayMinutes < 1) {
                continue;
            }

            if (mb_strlen($message) > $maxLen) {
                throw new InvalidArgumentException('Cada mensagem de recuperação deve ter no máximo '.$maxLen.' caracteres.');
            }

            $normalized[] = [
                'delay_minutes' => $delayMinutes,
                'message' => $message,
            ];
        }

        $previousDelay = 0;
        foreach ($normalized as $step) {
            if ($step['delay_minutes'] <= $previousDelay) {
                throw new InvalidArgumentException('Os tempos de envio devem ser crescentes (cada mensagem após a anterior).');
            }
            $previousDelay = $step['delay_minutes'];
        }

        if (count($normalized) > 10) {
            throw new InvalidArgumentException('Máximo de 10 mensagens de recuperação de carrinho.');
        }

        return array_values($normalized);
    }

    /**
     * @return array{delay_value: int, delay_unit: string, message: string}
     */
    private static function toUiStep(array $step): array
    {
        $delayMinutes = max(1, (int) ($step['delay_minutes'] ?? 1));
        $message = (string) ($step['message'] ?? '');

        if ($delayMinutes % 1440 === 0) {
            return [
                'delay_value' => (int) ($delayMinutes / 1440),
                'delay_unit' => 'days',
                'message' => $message,
            ];
        }

        if ($delayMinutes % 60 === 0) {
            return [
                'delay_value' => (int) ($delayMinutes / 60),
                'delay_unit' => 'hours',
                'message' => $message,
            ];
        }

        return [
            'delay_value' => $delayMinutes,
            'delay_unit' => 'minutes',
            'message' => $message,
        ];
    }

    private static function toDelayMinutes(int $value, string $unit): int
    {
        return match ($unit) {
            'hours' => $value * 60,
            'days' => $value * 1440,
            default => $value,
        };
    }

    public static function maxDelayMinutes(): int
    {
        return 43200;
    }

    public static function maxDelayMinutesLabel(): string
    {
        return '30 dias';
    }
}
