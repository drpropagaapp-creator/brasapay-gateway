<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

class MinimumChargeService
{
    public const SETTING_API_PIX = 'api_pix_minimum_charge_brl';

    public const SETTING_PLATFORM = 'platform_minimum_charge_brl';

    public const SETTING_API_PIX_MAX = 'api_pix_maximum_charge_brl';

    public const SETTING_PLATFORM_MAX = 'platform_maximum_charge_brl';

    public const DEFAULT_API_PIX = 0.01;

    public const DEFAULT_PLATFORM = 0.0;

    /** 0 = sem teto. */
    public const DEFAULT_MAXIMUM = 0.0;

    public function apiPixMinimumBrl(): float
    {
        return $this->readGlobalSetting(self::SETTING_API_PIX, self::DEFAULT_API_PIX);
    }

    public function platformMinimumBrl(): float
    {
        return $this->readGlobalSetting(self::SETTING_PLATFORM, self::DEFAULT_PLATFORM);
    }

    public function apiPixMaximumBrl(): float
    {
        return $this->readGlobalSetting(self::SETTING_API_PIX_MAX, self::DEFAULT_MAXIMUM);
    }

    public function platformMaximumBrl(): float
    {
        return $this->readGlobalSetting(self::SETTING_PLATFORM_MAX, self::DEFAULT_MAXIMUM);
    }

    public function apiPixMinimumBrlForTenant(?int $tenantId): float
    {
        $override = $this->readTenantOverride($tenantId, self::SETTING_API_PIX);
        if ($override !== null) {
            return $override;
        }

        return $this->apiPixMinimumBrl();
    }

    public function platformMinimumBrlForTenant(?int $tenantId): float
    {
        $override = $this->readTenantOverride($tenantId, self::SETTING_PLATFORM);
        if ($override !== null) {
            return $override;
        }

        return $this->platformMinimumBrl();
    }

    public function apiPixMaximumBrlForTenant(?int $tenantId): float
    {
        $override = $this->readTenantOverride($tenantId, self::SETTING_API_PIX_MAX);
        if ($override !== null) {
            return $override;
        }

        return $this->apiPixMaximumBrl();
    }

    public function platformMaximumBrlForTenant(?int $tenantId): float
    {
        $override = $this->readTenantOverride($tenantId, self::SETTING_PLATFORM_MAX);
        if ($override !== null) {
            return $override;
        }

        return $this->platformMaximumBrl();
    }

    /**
     * @return list<string|\Illuminate\Validation\Rules\Numeric>
     */
    public function platformPriceRules(?int $tenantId, bool $required = true): array
    {
        $min = $this->platformMinimumBrlForTenant($tenantId);
        $rules = array_values(array_filter([
            $required ? 'required' : null,
            'numeric',
            'min:'.$min,
        ]));
        $max = $this->platformMaximumBrlForTenant($tenantId);
        if ($max > 0) {
            $rules[] = 'max:'.$max;
        }

        return $rules;
    }

    public function tenantApiPixOverride(?int $tenantId): ?float
    {
        return $this->readTenantOverride($tenantId, self::SETTING_API_PIX);
    }

    public function tenantPlatformOverride(?int $tenantId): ?float
    {
        return $this->readTenantOverride($tenantId, self::SETTING_PLATFORM);
    }

    /**
     * @param  float|null  $apiMin  null removes tenant override (inherit global)
     * @param  float|null  $platformMin  null removes tenant override (inherit global)
     */
    public function setTenantOverrides(int $tenantId, ?float $apiMin, ?float $platformMin): void
    {
        $this->setTenantApiPixOverride($tenantId, $apiMin);
        $this->setTenantPlatformOverride($tenantId, $platformMin);
    }

    public function setTenantApiPixOverride(int $tenantId, ?float $value): void
    {
        $this->writeTenantOverride($tenantId, self::SETTING_API_PIX, $value);
    }

    public function setTenantPlatformOverride(int $tenantId, ?float $value): void
    {
        $this->writeTenantOverride($tenantId, self::SETTING_PLATFORM, $value);
    }

    public function assertApiPayment(float $amountBrl, ?int $tenantId = null): void
    {
        $min = $this->apiPixMinimumBrlForTenant($tenantId);
        if ($amountBrl + 1e-9 < $min) {
            throw ValidationException::withMessages([
                'amount' => $this->messageFor($min, 'api_pix'),
            ]);
        }

        $this->assertNotAbove($amountBrl, $this->apiPixMaximumBrlForTenant($tenantId), 'amount', 'api_pix');
    }

    public function assertPlatformCheckout(float $totalBrl, ?int $tenantId = null): void
    {
        $min = $this->platformMinimumBrlForTenant($tenantId);
        if ($min > 0 && $totalBrl + 1e-9 < $min) {
            throw ValidationException::withMessages([
                'amount' => $this->messageFor($min, 'platform_checkout'),
            ]);
        }

        $this->assertNotAbove($totalBrl, $this->platformMaximumBrlForTenant($tenantId), 'amount', 'platform_checkout');
    }

    public function assertPlatformProductPrice(float $priceBrl, ?int $tenantId = null): void
    {
        $min = $this->platformMinimumBrlForTenant($tenantId);
        if ($min > 0 && $priceBrl + 1e-9 < $min) {
            throw ValidationException::withMessages([
                'price' => $this->messageFor($min, 'platform_product'),
            ]);
        }

        $this->assertNotAbove($priceBrl, $this->platformMaximumBrlForTenant($tenantId), 'price', 'platform_product');
    }

    public function messageFor(float $min, string $channel): string
    {
        $formatted = $this->formatBrl($min);

        return match ($channel) {
            'api_pix' => "Valor mínimo para cobrança via API PIX é {$formatted}.",
            'platform_checkout' => "Valor mínimo para cobrança na plataforma é {$formatted}.",
            'platform_product' => "Ticket mínimo da plataforma é {$formatted}. Ajuste o preço do produto, oferta ou plano.",
            default => "Valor mínimo de cobrança é {$formatted}.",
        };
    }

    public function messageForMaximum(float $max, string $channel): string
    {
        $formatted = $this->formatBrl($max);

        return match ($channel) {
            'api_pix' => "Valor máximo para cobrança via API PIX é {$formatted}.",
            'platform_checkout' => "Valor máximo para cobrança na plataforma é {$formatted}.",
            'platform_product' => "Ticket máximo da plataforma é {$formatted}. Ajuste o preço do produto, oferta ou plano.",
            default => "Valor máximo de cobrança é {$formatted}.",
        };
    }

    public function formatBrl(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    private function assertNotAbove(float $amount, float $max, string $field, string $channel): void
    {
        if ($max <= 0) {
            return;
        }

        if ($amount - 1e-9 > $max) {
            throw ValidationException::withMessages([
                $field => $this->messageForMaximum($max, $channel),
            ]);
        }
    }

    private function readGlobalSetting(string $key, float $default): float
    {
        $raw = Setting::get($key, (string) $default, null);

        return max(0, round((float) $raw, 2));
    }

    private function readTenantOverride(?int $tenantId, string $key): ?float
    {
        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        $row = Setting::query()
            ->where('key', $key)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($row === null) {
            return null;
        }

        return max(0, round((float) $row->value, 2));
    }

    private function writeTenantOverride(int $tenantId, string $key, ?float $value): void
    {
        if ($value === null) {
            Setting::query()
                ->where('key', $key)
                ->where('tenant_id', $tenantId)
                ->delete();

            return;
        }

        Setting::set($key, (string) round(max(0, $value), 2), $tenantId);
    }
}
