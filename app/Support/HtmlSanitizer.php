<?php

namespace App\Support;

class HtmlSanitizer
{
    /**
     * Lista de tags permitidas para conteúdo de aula/seção (evita XSS).
     */
    private const ALLOWED_TAGS = '<p><br><strong><em><b><i><u><s><a><ul><ol><li><h1><h2><h3><h4><blockquote><pre><code><span><div>';

    /** Propriedades CSS permitidas na descrição da aula. */
    private const LESSON_STYLE_PROPS = ['color', 'font-size', 'text-align', 'font-weight', 'font-style', 'text-decoration'];

    /**
     * Sanitiza HTML para exibição segura (remove script, eventos, javascript:).
     */
    public static function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = self::removeEventHandlers($html);
        $html = self::removeJavascriptUrls($html);
        $html = self::removeDataUrls($html);

        return $html;
    }

    /**
     * Sanitiza descrição/conteúdo de aula.
     * Texto puro (sem tags) é devolvido intacto — não converte quebras de linha nem URLs.
     */
    public static function sanitizeLessonHtml(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        if (! preg_match('/<\/?[a-zA-Z][^>]*>/', $html)) {
            return $html;
        }

        $html = self::normalizeLegacyFormattingTags($html);
        $html = self::sanitize($html);
        $html = self::restrictInlineStyles($html);
        $html = self::forceSafeAnchors($html);

        return $html;
    }

    /**
     * Converte <font> e <strike> (saída clássica de execCommand) para tags da allowlist.
     */
    private static function normalizeLegacyFormattingTags(string $html): string
    {
        $html = preg_replace('/<strike\b[^>]*>/i', '<s>', $html) ?? $html;
        $html = preg_replace('/<\/strike>/i', '</s>', $html) ?? $html;

        for ($i = 0; $i < 8; $i++) {
            $next = preg_replace_callback(
                '/<font\b([^>]*)>(.*?)<\/font>/is',
                function (array $m): string {
                    $attrs = $m[1];
                    $styles = [];
                    if (preg_match('/\bcolor\s*=\s*["\']([^"\']+)["\']/i', $attrs, $c)) {
                        $styles[] = 'color: '.$c[1];
                    }
                    if (preg_match('/\bsize\s*=\s*["\']?(\d+)["\']?/i', $attrs, $s)) {
                        $map = [1 => '0.75em', 2 => '0.875em', 3 => '1em', 4 => '1.125em', 5 => '1.25em', 6 => '1.5em', 7 => '1.75em'];
                        $styles[] = 'font-size: '.($map[(int) $s[1]] ?? '1em');
                    }
                    if ($styles === []) {
                        return $m[2];
                    }

                    return '<span style="'.htmlspecialchars(implode('; ', $styles), ENT_QUOTES, 'UTF-8').'">'.$m[2].'</span>';
                },
                $html
            );
            if (! is_string($next) || $next === $html) {
                break;
            }
            $html = $next;
        }

        return $html;
    }

    private static function restrictInlineStyles(string $html): string
    {
        return (string) preg_replace_callback(
            '/\sstyle\s*=\s*(["\'])(.*?)\1/i',
            function (array $m): string {
                $filtered = self::filterStyleAttribute($m[2]);
                if ($filtered === '') {
                    return '';
                }

                return ' style='.$m[1].$filtered.$m[1];
            },
            $html
        );
    }

    private static function filterStyleAttribute(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, ':')) {
                continue;
            }
            [$prop, $value] = array_map('trim', explode(':', $part, 2));
            $prop = strtolower($prop);
            if (! in_array($prop, self::LESSON_STYLE_PROPS, true)) {
                continue;
            }
            $lower = strtolower($value);
            if ($value === '' || preg_match('/url\s*\(|expression\s*\(|javascript:|@import|behavior/i', $lower)) {
                continue;
            }
            if ($prop === 'text-align' && ! preg_match('/^(left|right|center|justify|start|end)$/i', $value)) {
                continue;
            }
            if ($prop === 'font-size' && ! preg_match('/^(xx-small|x-small|small|medium|large|x-large|xx-large|\d+(\.\d+)?(px|em|rem|%))$/i', $value)) {
                continue;
            }
            if ($prop === 'font-size' && preg_match('/^(\d+(\.\d+)?)px$/i', $value, $px) && (float) $px[1] > 32) {
                $value = '32px';
            }
            if ($prop === 'color' && ! preg_match('/^(#[0-9a-f]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-z]+)$/i', $value)) {
                continue;
            }
            $kept[] = $prop.': '.$value;
        }

        return implode('; ', $kept);
    }

    private static function forceSafeAnchors(string $html): string
    {
        return (string) preg_replace_callback(
            '/<a\b([^>]*)>/i',
            function (array $m): string {
                $attrs = $m[1];
                if (! preg_match('/\btarget\s*=/i', $attrs)) {
                    $attrs .= ' target="_blank"';
                }
                if (! preg_match('/\brel\s*=/i', $attrs)) {
                    $attrs .= ' rel="noopener noreferrer"';
                }

                return '<a'.$attrs.'>';
            },
            $html
        );
    }

    private static function removeEventHandlers(string $html): string
    {
        return (string) preg_replace_callback(
            '/<(\w+)([^>]*)>/i',
            function (array $m): string {
                $attrs = (string) preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $m[2]);
                return '<' . $m[1] . $attrs . '>';
            },
            $html
        );
    }

    private static function removeJavascriptUrls(string $html): string
    {
        $html = (string) preg_replace(
            '/href\s*=\s*["\']\s*javascript\s*:[^"\']*["\']/i',
            'href="#"',
            $html
        );
        $html = (string) preg_replace(
            '/src\s*=\s*["\']\s*javascript\s*:[^"\']*["\']/i',
            'src=""',
            $html
        );

        return $html;
    }

    private static function removeDataUrls(string $html): string
    {
        return (string) preg_replace(
            '/\b(src|href)\s*=\s*["\']\s*data\s*:[^"\']*["\']/i',
            '$1=""',
            $html
        );
    }

    /**
     * Sanitiza texto puro (sem HTML) para armazenar em campos comuns (nome, endereço, etc.).
     * Remove tags, caracteres de controlo e normaliza espaços.
     */
    public static function plainText(?string $text, int $maxLen = 255): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $s = strip_tags($text);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s) ?? '';
        $s = preg_replace('/\s+/u', ' ', (string) $s) ?? '';
        $s = trim($s);

        if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
            $s = mb_substr($s, 0, $maxLen);
        }

        return $s;
    }

    /**
     * Texto puro preservando quebras de linha (ex.: observações). Remove tags e caracteres de controlo.
     */
    public static function plainTextMultiline(?string $text, int $maxLen = 2000): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $s = strip_tags($text);
        $s = str_replace(["\r\n", "\r"], "\n", (string) $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s) ?? '';
        // Normaliza múltiplas linhas vazias
        $s = preg_replace("/\n{3,}/", "\n\n", (string) $s) ?? '';
        $s = trim($s);

        if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
            $s = mb_substr($s, 0, $maxLen);
        }

        return $s;
    }
}
