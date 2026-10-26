<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Aceitação automática de produtos no marketplace da plataforma.
 * Ligada: o opt-in do infoprodutor já autoriza a exibição.
 * Desligada: o admin precisa autorizar cada produto.
 */
final class MarketplaceSettings
{
    public const KEY = 'marketplace_auto_accept';

    public static function autoAcceptEnabled(): bool
    {
        return Setting::get(self::KEY, '1', null) === '1';
    }

    public static function setAutoAccept(bool $enabled): void
    {
        Setting::set(self::KEY, $enabled ? '1' : '0', null);
    }
}
