<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerLessonHtmlTest extends TestCase
{
    public function test_plain_text_with_newlines_and_urls_is_unchanged(): void
    {
        $text = "Assista a aula.\n\nMaterial: https://drive.google.com/file/d/abc\nObrigado!";

        $this->assertSame($text, HtmlSanitizer::sanitizeLessonHtml($text));
    }

    public function test_null_and_empty_stay_empty(): void
    {
        $this->assertSame('', HtmlSanitizer::sanitizeLessonHtml(null));
        $this->assertSame('', HtmlSanitizer::sanitizeLessonHtml(''));
    }

    public function test_formatted_html_keeps_safe_tags_and_link(): void
    {
        $html = '<p>Leia o <strong>guia</strong> e <a href="https://example.com/material.pdf">Baixar material</a>.</p><ol><li>Passo 1</li></ol>';
        $out = HtmlSanitizer::sanitizeLessonHtml($html);

        $this->assertStringContainsString('<strong>guia</strong>', $out);
        $this->assertStringContainsString('Baixar material', $out);
        $this->assertStringContainsString('https://example.com/material.pdf', $out);
        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringContainsString('<ol>', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function test_scripts_and_dangerous_styles_are_stripped(): void
    {
        $html = '<p style="color: #dc2626; position: absolute; background: url(javascript:alert(1))">x</p><script>alert(1)</script>';
        $out = HtmlSanitizer::sanitizeLessonHtml($html);

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('position', $out);
        $this->assertStringNotContainsString('javascript', strtolower($out));
        $this->assertStringContainsString('color: #dc2626', $out);
    }

    public function test_legacy_font_and_strike_are_normalized(): void
    {
        $html = '<font color="#dc2626" size="5">Título</font> <strike>velho</strike>';
        $out = HtmlSanitizer::sanitizeLessonHtml($html);

        $this->assertStringNotContainsString('<font', strtolower($out));
        $this->assertStringNotContainsString('<strike', strtolower($out));
        $this->assertStringContainsString('color: #dc2626', $out);
        $this->assertStringContainsString('<s>velho</s>', $out);
    }
}
