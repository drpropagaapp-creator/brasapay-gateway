<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Configuração global: aprovar novos produtos automaticamente ou enviar para análise.
 * Em modo manual, a fila aparece em Plataforma → Produtos com contador até aceitar ou recusar.
 */
final class ProductApprovalSettings
{
    public const KEY = 'auto_approve_products';

    public static function autoApproveEnabled(): bool
    {
        return Setting::get(self::KEY, '1', null) === '1';
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSettingsForm(): array
    {
        return [
            self::KEY => self::autoApproveEnabled() ? '1' : '0',
        ];
    }
}
