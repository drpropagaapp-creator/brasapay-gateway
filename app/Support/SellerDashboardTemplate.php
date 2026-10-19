<?php

namespace App\Support;

/**
 * O painel tem um único visual (Paynext Design System). Os templates de dashboard
 * (Aurora, Kawaii, Prime, Studio) foram removidos; valores antigos gravados em
 * settings são ignorados e tudo resolve para o padrão.
 */
class SellerDashboardTemplate
{
    public const KEY = 'seller_dashboard_template';

    public const DEFAULT = 'default';

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [self::DEFAULT];
    }

    public static function resolve(?string $raw): string
    {
        return self::DEFAULT;
    }

    public static function current(): string
    {
        return self::DEFAULT;
    }
}
