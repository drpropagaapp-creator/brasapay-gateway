<?php

namespace App\Support\Landing;

/**
 * CSS personalizado de um bloco da landing, confinado ao próprio bloco.
 *
 * - Todo seletor vira descendente do bloco (`.lpb-<id> …`); seletores que
 *   alcançariam o contêiner ou fora dele (html/body/:root, combinador inicial,
 *   aninhamento) são descartados. Não há como mirar o formulário de pagamento.
 * - Aspas e escapes são removidos antes da análise e a saída é reconferida.
 * - Remove @import/@charset/@namespace/@font-face, `expression()`, `behavior`,
 *   `-moz-binding`, `javascript:`, URLs que não sejam https e `</`.
 * - Remove `position: fixed|sticky`: nada sai do bloco para cobrir ou imitar o
 *   checkout (o renderer também aplica `contain: paint` no contêiner).
 * - Mantém @media (escopando o conteúdo) e @keyframes.
 */
final class LandingCssScoper
{
    public const MAX_LENGTH = 20000;

    public static function scope(?string $css, string $scope): string
    {
        $css = (string) $css;
        if (trim($css) === '') {
            return '';
        }

        $css = mb_substr($css, 0, self::MAX_LENGTH);
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        // Sem aspas e sem escapes: strings CSS poderiam esconder chaves e fazer este
        // parser enxergar uma estrutura diferente da do navegador.
        $css = str_replace(['</', '<!--', '-->', '\\', '"', "'"], '', $css);
        if (! self::bracesBalanced($css)) {
            return '';
        }
        $css = preg_replace('/@(import|charset|namespace|font-face)\b[^;{]*(;|\{[^}]*\})/i', '', $css) ?? '';

        $out = trim(self::scopeBlock($css, $scope));

        return self::everyRuleIsScoped($out, $scope) ? $out : '';
    }

    private static function bracesBalanced(string $css): bool
    {
        $depth = 0;
        $len = strlen($css);
        for ($i = 0; $i < $len; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /** Conferência final: todo seletor de regra comum começa pelo escopo do bloco. */
    private static function everyRuleIsScoped(string $css, string $scope): bool
    {
        $flat = preg_replace('/@(-webkit-)?keyframes\s+[\w-]+\{(?:[^{}]*\{[^{}]*\})*\}/i', '', $css) ?? '';
        $flat = preg_replace('/@media[^{]*\{/i', '', $flat) ?? '';
        if (! preg_match_all('/(?:^|\})\s*([^{}]+)\{/', $flat, $m)) {
            return true;
        }
        foreach ($m[1] as $prelude) {
            foreach (explode(',', $prelude) as $sel) {
                if (! str_starts_with(trim($sel), $scope.' ')) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function scopeBlock(string $css, string $scope): string
    {
        $out = '';
        $len = strlen($css);
        $i = 0;

        while ($i < $len) {
            $open = strpos($css, '{', $i);
            if ($open === false) {
                break;
            }
            $prelude = trim(substr($css, $i, $open - $i));
            $close = self::matchingBrace($css, $open);
            if ($close === null) {
                break;
            }
            $body = substr($css, $open + 1, $close - $open - 1);
            $i = $close + 1;

            if ($prelude === '') {
                continue;
            }

            if (stripos($prelude, '@media') === 0) {
                $inner = self::scopeBlock($body, $scope);
                if ($inner !== '' && preg_match('/^@media[\w\s:(),.\-]+$/i', $prelude)) {
                    $out .= $prelude.'{'.$inner.'}';
                }

                continue;
            }

            if (preg_match('/^@(-webkit-)?keyframes\s+[\w-]+$/i', $prelude)) {
                $out .= $prelude.'{'.self::cleanKeyframes($body).'}';

                continue;
            }

            if ($prelude[0] === '@') {
                continue;
            }

            $selectors = [];
            foreach (explode(',', $prelude) as $sel) {
                $sel = trim($sel);
                // Descarta o que alcançaria o próprio contêiner ou fora dele: html/body/:root
                // (viraria o bloco em si), combinador inicial (~ + > = irmão/filho do escopo)
                // e aninhamento (&).
                if ($sel === ''
                    || preg_match('/^(html|body|:root)\b/i', $sel)
                    || preg_match('/^[~+>]/', $sel)
                    || str_contains($sel, '&')) {
                    continue;
                }
                $selectors[] = $scope.' '.$sel;
            }
            $decl = self::cleanDeclarations($body);
            if ($selectors !== [] && $decl !== '') {
                $out .= implode(',', $selectors).'{'.$decl.'}';
            }
        }

        return $out;
    }

    private static function cleanKeyframes(string $body): string
    {
        $out = '';
        if (preg_match_all('/([\w%.,\s]+)\{([^{}]*)\}/', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $frame) {
                $out .= trim($frame[1]).'{'.self::cleanDeclarations($frame[2]).'}';
            }
        }

        return $out;
    }

    private static function cleanDeclarations(string $body): string
    {
        $kept = [];
        foreach (explode(';', $body) as $decl) {
            $decl = trim($decl);
            if ($decl === '' || ! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $value] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            if (! preg_match('/^-?[a-z][a-z0-9-]*$/', $prop) || in_array($prop, ['behavior', '-moz-binding'], true)) {
                continue;
            }
            $lv = strtolower($value);
            if (str_contains($lv, 'expression(') || str_contains($lv, 'javascript:') || str_contains($lv, '@import')) {
                continue;
            }
            if ($prop === 'position' && preg_match('/\b(fixed|sticky)\b/', $lv)) {
                continue;
            }
            // Só imagens https; data:, http: e caminhos relativos são descartados.
            if (str_contains($lv, 'url(')
                && preg_match_all('/url\(/i', $value) !== preg_match_all('/url\(\s*[\'"]?https:\/\//i', $value)) {
                continue;
            }
            $kept[] = $prop.':'.$value;
        }

        return implode(';', $kept);
    }

    private static function matchingBrace(string $css, int $open): ?int
    {
        $depth = 0;
        $len = strlen($css);
        for ($j = $open; $j < $len; $j++) {
            if ($css[$j] === '{') {
                $depth++;
            } elseif ($css[$j] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return null;
    }
}
