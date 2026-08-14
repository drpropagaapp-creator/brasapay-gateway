<?php

namespace App\Support;

final class CheckoutConfigUrlSanitizer
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function sanitize(array $config): array
    {
        if (isset($config['support_button']) && is_array($config['support_button'])) {
            $url = $config['support_button']['url'] ?? null;
            $safe = SafeUrl::normalizeHttpUrl(is_string($url) ? $url : null);
            $config['support_button']['url'] = $safe ?? '#';
        }

        if (isset($config['reviews']) && is_array($config['reviews'])) {
            foreach ($config['reviews'] as $i => $review) {
                if (! is_array($review)) {
                    continue;
                }
                foreach (['photo', 'testimonial_image', 'image', 'avatar'] as $imgKey) {
                    if (! isset($review[$imgKey]) || ! is_string($review[$imgKey])) {
                        continue;
                    }
                    $safe = SafeUrl::normalizeHttpUrl($review[$imgKey]);
                    $config['reviews'][$i][$imgKey] = $safe ?? '';
                }
                if (isset($review['author']) && is_string($review['author'])) {
                    $config['reviews'][$i]['author'] = HtmlSanitizer::plainText($review['author'], 120);
                }
                if (isset($review['description']) && is_string($review['description'])) {
                    $config['reviews'][$i]['description'] = HtmlSanitizer::plainTextMultiline($review['description'], 2000);
                }
            }
        }

        // Embed VTurb: aceito apenas se referencia o player oficial (converteai.net).
        // O componente do checkout só monta o placeholder e carrega scripts converteai.net —
        // nenhum JS inline do vendedor é executado.
        if (isset($config['vturb_embed']) && is_string($config['vturb_embed'])) {
            $embed = trim($config['vturb_embed']);
            if (mb_strlen($embed) > 6000) {
                $embed = mb_substr($embed, 0, 6000);
            }
            $config['vturb_embed'] = str_contains($embed, 'converteai.net') ? $embed : '';
        }

        if (isset($config['landing']) && is_array($config['landing'])) {
            if (isset($config['landing']['hero_image']) && is_string($config['landing']['hero_image'])) {
                $safe = SafeUrl::normalizeHttpUrl($config['landing']['hero_image']);
                $config['landing']['hero_image'] = $safe ?? '';
            }
            if (isset($config['landing']['images']) && is_array($config['landing']['images'])) {
                $config['landing']['images'] = array_values(array_filter(array_map(
                    fn ($url) => is_string($url) ? SafeUrl::normalizeHttpUrl($url) : null,
                    $config['landing']['images']
                )));
            }
            if (isset($config['landing']['custom_html']) && is_string($config['landing']['custom_html'])) {
                $config['landing']['custom_html'] = HtmlSanitizer::richBlock($config['landing']['custom_html']);
            }
            foreach (['headline' => 200, 'subheadline' => 500, 'cta_text' => 80, 'benefits_title' => 120] as $textKey => $max) {
                if (isset($config['landing'][$textKey]) && is_string($config['landing'][$textKey])) {
                    $config['landing'][$textKey] = HtmlSanitizer::plainText($config['landing'][$textKey], $max);
                }
            }
            if (isset($config['landing']['benefits']) && is_string($config['landing']['benefits'])) {
                $config['landing']['benefits'] = HtmlSanitizer::plainTextMultiline($config['landing']['benefits'], 4000);
            }
            $config['landing']['sections'] = self::normalizeLandingSections(
                $config['landing']['sections'] ?? null
            );
        }

        if (isset($config['footer']) && is_array($config['footer'])) {
            foreach (['privacy_url', 'terms_url', 'refund_url'] as $urlKey) {
                if (! isset($config['footer'][$urlKey]) || ! is_string($config['footer'][$urlKey])) {
                    continue;
                }
                $safe = SafeUrl::normalizeHttpUrl($config['footer'][$urlKey]);
                $config['footer'][$urlKey] = $safe ?? '';
            }
        }

        if (isset($config['redirect_after_purchase']) && is_string($config['redirect_after_purchase'])) {
            $config['redirect_after_purchase'] = SafeUrl::normalizeCheckoutRedirect($config['redirect_after_purchase']) ?? '';
        }

        if (isset($config['deliverable_link']) && is_string($config['deliverable_link'])) {
            $config['deliverable_link'] = SafeUrl::normalizeCheckoutRedirect($config['deliverable_link']) ?? '';
        }

        return $config;
    }

    private const LANDING_SECTION_IDS = [
        'headline', 'subheadline', 'cta', 'video', 'hero_image',
        'images', 'custom_html', 'benefits', 'reviews', 'cta_final',
    ];

    /**
     * Normaliza a ordem/visibilidade dos elementos da landing: só ids conhecidos,
     * sem duplicatas, e ids novos (de versões futuras) anexados ao final.
     *
     * @return list<array{id: string, visible: bool}>
     */
    private static function normalizeLandingSections(mixed $sections): array
    {
        $normalized = [];
        $seen = [];

        if (is_array($sections)) {
            foreach ($sections as $section) {
                $id = is_array($section) ? ($section['id'] ?? null) : null;
                if (! is_string($id) || ! in_array($id, self::LANDING_SECTION_IDS, true) || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $normalized[] = [
                    'id' => $id,
                    'visible' => filter_var($section['visible'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ];
            }
        }

        foreach (self::LANDING_SECTION_IDS as $id) {
            if (! isset($seen[$id])) {
                $normalized[] = ['id' => $id, 'visible' => true];
            }
        }

        return $normalized;
    }
}
