<?php

namespace Tests\Unit;

use App\Support\CheckoutVideoIframe;
use PHPUnit\Framework\TestCase;

class CheckoutVideoIframeTest extends TestCase
{
    public function test_accepts_direct_link(): void
    {
        $url = 'https://drpropaga-vsl.vercel.app/p/accxtdau';
        $this->assertSame($url, CheckoutVideoIframe::normalize($url));
    }

    public function test_extracts_src_from_iframe_code(): void
    {
        $code = '<div style="position:relative;padding-top:56.25%"><iframe src="https://drpropaga-vsl.vercel.app/p/accxtdau" style="position:absolute" allowfullscreen></iframe></div>';
        $this->assertSame('https://drpropaga-vsl.vercel.app/p/accxtdau', CheckoutVideoIframe::normalize($code));
    }

    public function test_rejects_other_hosts_http_and_scripts(): void
    {
        $this->assertSame('', CheckoutVideoIframe::normalize('https://evil.example.com/p/x'));
        $this->assertSame('', CheckoutVideoIframe::normalize('http://drpropaga-vsl.vercel.app/p/x'));
        $this->assertSame('', CheckoutVideoIframe::normalize('javascript:alert(1)'));
        $this->assertSame('', CheckoutVideoIframe::normalize('https://drpropaga-vsl.vercel.app.evil.com/p/x'));
        $this->assertSame('', CheckoutVideoIframe::normalize('<script>alert(1)</script>'));
        $this->assertSame('', CheckoutVideoIframe::normalize(''));
    }
}
