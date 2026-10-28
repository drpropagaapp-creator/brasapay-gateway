<?php

namespace Tests\Unit;

use App\Support\Landing\LandingBlockSchema;
use App\Support\Landing\LandingCssScoper;
use Tests\TestCase;

class LandingBlockSchemaTest extends TestCase
{
    public function test_tipos_desconhecidos_sao_descartados_e_ids_gerados(): void
    {
        $blocks = LandingBlockSchema::sanitize([
            ['type' => 'heading', 'id' => 'bom-id-1', 'props' => ['text' => 'Oi']],
            ['type' => 'script', 'props' => ['code' => 'alert(1)']],
            ['type' => 'text', 'id' => '../evil', 'props' => ['html' => '<p>ok</p>']],
        ]);

        $this->assertCount(2, $blocks);
        $this->assertSame('bom-id-1', $blocks[0]['id']);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{4,40}$/', $blocks[1]['id']);
    }

    public function test_html_do_bloco_e_sanitizado(): void
    {
        $blocks = LandingBlockSchema::sanitize([
            ['type' => 'text', 'props' => ['html' => '<p onclick="steal()">a</p><script>alert(1)</script><a href="javascript:x()">l</a>']],
        ]);
        $html = $blocks[0]['props']['html'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_cta_so_aceita_acoes_declaradas_e_urls_http(): void
    {
        $blocks = LandingBlockSchema::sanitize([
            ['type' => 'button', 'props' => ['text' => 'Comprar', 'action' => 'eval', 'target' => 'javascript:alert(1)']],
        ]);

        $this->assertSame('scroll_checkout', $blocks[0]['props']['action']);
        $this->assertSame('', $blocks[0]['props']['target']);
    }

    public function test_apenas_um_bloco_de_checkout(): void
    {
        $blocks = LandingBlockSchema::sanitize([['type' => 'checkout'], ['type' => 'checkout']]);

        $this->assertCount(1, $blocks);
    }

    public function test_css_fica_confinado_ao_bloco_e_sem_construcoes_perigosas(): void
    {
        $css = LandingCssScoper::scope(
            'body{color:red} .x,h2{position:fixed;top:0;color:blue} #checkout-purchase-area{display:none}'
            .' @import url(https://evil); .y{background:url(javascript:alert(1))}'
            .' @media (max-width: 600px){ .z{font-size:12px} } </style><script>',
            '.lpb-abc1'
        );

        $this->assertStringNotContainsString('position:fixed', $css);
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('javascript', $css);
        $this->assertStringNotContainsString('</', $css);
        $this->assertStringContainsString('.lpb-abc1 #checkout-purchase-area', $css, 'Seletor do checkout só existe dentro do bloco.');
        $this->assertStringContainsString('@media (max-width: 600px){.lpb-abc1 .z{font-size:12px}}', $css);
        foreach (preg_split('/[{}]/', $css) as $chunk) {
            if (str_contains($chunk, ':') && ! str_contains($chunk, '@media')) {
                continue;
            }
            $chunk = trim($chunk);
            if ($chunk === '' || str_starts_with($chunk, '@media')) {
                continue;
            }
            foreach (explode(',', $chunk) as $sel) {
                $this->assertStringStartsWith('.lpb-abc1', trim($sel));
            }
        }
    }

    public function test_seletores_que_escapariam_do_bloco_sao_descartados(): void
    {
        $css = LandingCssScoper::scope(
            'body{contain:none;position:absolute;z-index:9999} :root{display:none}'
            .' ~ #checkout-purchase-area{display:none} + div{opacity:0} > .x{color:red} & .y{color:red} .ok{color:green}',
            '.lpb-abc1'
        );

        $this->assertSame('.lpb-abc1 .ok{color:green}', $css);
    }

    public function test_aspas_nao_dessincronizam_o_parser(): void
    {
        $css = LandingCssScoper::scope('.a{content:"}"} body{color:red} .b{color:blue}', '.lpb-abc1');

        $this->assertStringNotContainsString('"', $css);
        foreach (array_filter(array_map('trim', preg_split('/\{[^}]*\}/', $css))) as $sel) {
            $this->assertStringStartsWith('.lpb-abc1 ', $sel);
        }
    }

    public function test_chaves_desbalanceadas_invalidam_o_css(): void
    {
        $this->assertSame('', LandingCssScoper::scope('.a{color:red} } body{color:blue', '.lpb-abc1'));
    }

    public function test_bloco_html_gera_css_escopado(): void
    {
        $blocks = LandingBlockSchema::sanitize([
            ['type' => 'html', 'id' => 'meu-bloco', 'props' => ['html' => '<div class="x">a</div>', 'css' => '.x{color:red}']],
        ]);

        $this->assertSame('.lpb-meu-bloco .x{color:red}', $blocks[0]['props']['css_scoped']);
    }

    public function test_estilo_responsivo_e_limitado(): void
    {
        $blocks = LandingBlockSchema::sanitize([
            ['type' => 'heading', 'style' => ['pad_y' => 9999, 'bg' => 'red;background:url(x)', 'hide_mobile' => 'true', 'font_scale_mobile' => 10]],
        ]);
        $style = $blocks[0]['style'];

        $this->assertSame(160, $style['pad_y']);
        $this->assertSame('', $style['bg']);
        $this->assertTrue($style['hide_mobile']);
        $this->assertSame(75, $style['font_scale_mobile']);
    }
}
