<?php

namespace App\Support;

/**
 * As telas de autenticação têm um único layout (login imersivo do Paynext Design System).
 * As variantes Spotlight e Immersive foram removidas; valores antigos em settings são ignorados.
 */
class LoginTemplate
{
    public const KEY = 'login_template';

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
