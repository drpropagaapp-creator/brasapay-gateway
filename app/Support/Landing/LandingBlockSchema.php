<?php

namespace App\Support\Landing;

use App\Support\HtmlSanitizer;
use App\Support\SafeUrl;
use Illuminate\Support\Str;

/**
 * Blocos da landing do checkout: só tipos conhecidos, cada propriedade validada
 * pelo seu tipo. Nada é salvo como código executável — CTAs são ações
 * declaradas, HTML passa pelo sanitizador e CSS fica confinado ao bloco.
 */
final class LandingBlockSchema
{
    public const MAX_BLOCKS = 60;

    public const MAX_ITEMS = 30;

    public const CTA_ACTIONS = ['scroll_checkout', 'scroll_block', 'url'];

    /** tipo => definição das propriedades */
    private const TYPES = [
        'hero' => [
            'title' => ['text', 200], 'subtitle' => ['multiline', 600], 'image' => ['url'],
            'button_text' => ['text', 80], 'button_action' => ['cta'], 'button_target' => ['target'],
            'layout' => ['enum', ['stacked', 'split']],
        ],
        'heading' => ['text' => ['text', 200], 'level' => ['enum', ['h1', 'h2', 'h3']]],
        'subheading' => ['text' => ['multiline', 600]],
        'text' => ['html' => ['html', 20000]],
        'image' => ['url' => ['url'], 'alt' => ['text', 200], 'link' => ['url']],
        'gallery' => ['items' => ['list', ['url' => ['url'], 'alt' => ['text', 200]]], 'columns' => ['int', 1, 4]],
        'video' => ['provider' => ['enum', ['youtube', 'vturb']], 'url' => ['url']],
        'button' => [
            'text' => ['text', 80], 'action' => ['cta'], 'target' => ['target'],
            'variant' => ['enum', ['solid', 'outline']],
        ],
        'benefits' => ['title' => ['text', 200], 'items' => ['list', ['text' => ['text', 300]]]],
        'features' => ['title' => ['text', 200], 'items' => ['list', ['title' => ['text', 120], 'text' => ['multiline', 600]]]],
        'cards' => ['title' => ['text', 200], 'items' => ['list', ['title' => ['text', 120], 'text' => ['multiline', 600], 'image' => ['url']]]],
        'testimonials' => ['title' => ['text', 200], 'items' => ['list', ['name' => ['text', 120], 'text' => ['multiline', 1000], 'image' => ['url'], 'rating' => ['int', 0, 5]]]],
        'faq' => ['title' => ['text', 200], 'items' => ['list', ['q' => ['text', 300], 'a' => ['multiline', 2000]]]],
        'plans' => ['title' => ['text', 200], 'items' => ['list', ['name' => ['text', 120], 'price' => ['text', 60], 'features' => ['multiline', 1200], 'highlight' => ['bool'], 'button_text' => ['text', 80]]]],
        'pricing' => [
            'title' => ['text', 200], 'old_price' => ['text', 60], 'price' => ['text', 60],
            'note' => ['multiline', 400], 'button_text' => ['text', 80],
        ],
        'countdown' => ['title' => ['text', 200], 'mode' => ['enum', ['evergreen', 'fixed']], 'minutes' => ['int', 1, 10080], 'ends_at' => ['datetime']],
        'guarantee' => ['title' => ['text', 200], 'text' => ['multiline', 1200], 'days' => ['int', 0, 365], 'image' => ['url']],
        'bonus' => ['title' => ['text', 200], 'items' => ['list', ['title' => ['text', 120], 'text' => ['multiline', 600], 'value' => ['text', 60]]]],
        'logos' => ['title' => ['text', 200], 'items' => ['list', ['url' => ['url'], 'alt' => ['text', 120]]]],
        'divider' => [],
        'spacer' => ['height' => ['int', 8, 240]],
        'footer' => ['text' => ['multiline', 1200]],
        'html' => ['html' => ['html', 30000], 'css' => ['css']],
        // Marca onde entra o formulário oficial de pagamento da BrasaPay (inclui
        // resumo do pedido e order bumps). No máximo um por página.
        'checkout' => [],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sanitize(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $out = [];
        $ids = [];
        $hasCheckout = false;

        foreach (array_slice(array_values($blocks), 0, self::MAX_BLOCKS) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $type = (string) ($raw['type'] ?? '');
            if (! array_key_exists($type, self::TYPES)) {
                continue;
            }
            if ($type === 'checkout') {
                if ($hasCheckout) {
                    continue;
                }
                $hasCheckout = true;
            }

            $id = (string) ($raw['id'] ?? '');
            if (! preg_match('/^[a-z0-9-]{4,40}$/', $id) || isset($ids[$id])) {
                $id = 'b'.strtolower(Str::random(10));
            }
            $ids[$id] = true;

            $props = self::sanitizeProps(self::TYPES[$type], is_array($raw['props'] ?? null) ? $raw['props'] : []);
            if ($type === 'html') {
                $props['css_scoped'] = LandingCssScoper::scope($props['css'] ?? '', '.lpb-'.$id);
            }

            $out[] = [
                'id' => $id,
                'type' => $type,
                'props' => $props,
                'style' => self::sanitizeStyle(is_array($raw['style'] ?? null) ? $raw['style'] : []),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, mixed>>  $schema
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function sanitizeProps(array $schema, array $props): array
    {
        $out = [];
        foreach ($schema as $key => $rule) {
            $out[$key] = self::sanitizeValue($rule, $props[$key] ?? null);
        }

        return $out;
    }

    /** @param  array<int, mixed>  $rule */
    private static function sanitizeValue(array $rule, mixed $value): mixed
    {
        return match ($rule[0]) {
            'text' => HtmlSanitizer::plainText(is_scalar($value) ? (string) $value : '', (int) $rule[1]),
            'multiline' => HtmlSanitizer::plainTextMultiline(is_scalar($value) ? (string) $value : '', (int) $rule[1]),
            'html' => HtmlSanitizer::richBlock(is_string($value) ? $value : '', (int) $rule[1]),
            'css' => is_string($value) ? mb_substr($value, 0, LandingCssScoper::MAX_LENGTH) : '',
            'url' => is_string($value) && trim($value) !== '' ? (SafeUrl::normalizeHttpUrl($value) ?? '') : '',
            'enum' => in_array($value, $rule[1], true) ? $value : $rule[1][0],
            'int' => is_numeric($value) ? max($rule[1], min($rule[2], (int) $value)) : $rule[1],
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'cta' => in_array($value, self::CTA_ACTIONS, true) ? $value : 'scroll_checkout',
            // Destino da ação: id de bloco ou URL http(s); o renderer decide pelo tipo da ação.
            'target' => is_string($value) && preg_match('/^[a-z0-9-]{4,40}$/', $value)
                ? $value
                : (is_string($value) ? (SafeUrl::normalizeHttpUrl($value) ?? '') : ''),
            'datetime' => is_string($value) && strtotime($value) !== false ? date('c', (int) strtotime($value)) : '',
            'list' => self::sanitizeList($rule[1], $value),
            default => null,
        };
    }

    /**
     * @param  array<string, array<int, mixed>>  $itemSchema
     * @return list<array<string, mixed>>
     */
    private static function sanitizeList(array $itemSchema, mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($items), 0, self::MAX_ITEMS) as $item) {
            if (is_array($item)) {
                $out[] = self::sanitizeProps($itemSchema, $item);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private static function sanitizeStyle(array $style): array
    {
        $color = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : '';

        return [
            'align' => in_array($style['align'] ?? null, ['left', 'center', 'right'], true) ? $style['align'] : 'center',
            'width' => in_array($style['width'] ?? null, ['narrow', 'normal', 'wide', 'full'], true) ? $style['width'] : 'normal',
            'pad_y' => is_numeric($style['pad_y'] ?? null) ? max(0, min(160, (int) $style['pad_y'])) : 24,
            'pad_y_mobile' => is_numeric($style['pad_y_mobile'] ?? null) ? max(0, min(160, (int) $style['pad_y_mobile'])) : null,
            'radius' => is_numeric($style['radius'] ?? null) ? max(0, min(48, (int) $style['radius'])) : 0,
            'bg' => $color($style['bg'] ?? null),
            'text' => $color($style['text'] ?? null),
            'font_scale' => is_numeric($style['font_scale'] ?? null) ? max(75, min(150, (int) $style['font_scale'])) : 100,
            'font_scale_mobile' => is_numeric($style['font_scale_mobile'] ?? null) ? max(75, min(150, (int) $style['font_scale_mobile'])) : null,
            'hide_mobile' => filter_var($style['hide_mobile'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'hide_desktop' => filter_var($style['hide_desktop'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
