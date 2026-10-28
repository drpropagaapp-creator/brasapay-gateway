<?php

namespace Tests\Feature;

use App\Support\CheckoutEmbed;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Cookie de sessão particionado só no checkout embutido em site de terceiro.
 * Antes, acesso direto ao checkout e a prévia do builder gravavam uma segunda cópia
 * (particionada) do cookie de sessão; após o login o navegador mandava as duas e o 2FA
 * respondia "Sessão de verificação expirada".
 */
class CheckoutEmbedSessionCookieTest extends TestCase
{
    private function req(string $uri, array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return Request::create($uri, 'GET', [], [], [], $server);
    }

    public function test_acesso_direto_ao_checkout_nao_particiona(): void
    {
        $this->assertFalse(CheckoutEmbed::shouldPartitionSession($this->req('/c/abc123', ['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Site' => 'none'])));
        $this->assertFalse(CheckoutEmbed::shouldPartitionSession($this->req('/checkout/pix/tok', ['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Site' => 'same-origin'])));
    }

    public function test_previa_do_builder_nao_particiona_mesmo_em_iframe(): void
    {
        $this->assertFalse(CheckoutEmbed::shouldPartitionSession($this->req('/c/abc123?preview=1', ['Sec-Fetch-Dest' => 'iframe', 'Sec-Fetch-Site' => 'same-origin'])));
    }

    public function test_iframe_de_terceiro_e_requisicoes_marcadas_particionam(): void
    {
        $this->assertTrue(CheckoutEmbed::shouldPartitionSession($this->req('/c/abc123', ['Sec-Fetch-Dest' => 'iframe', 'Sec-Fetch-Site' => 'cross-site'])));
        $this->assertTrue(CheckoutEmbed::shouldPartitionSession($this->req('/checkout/pix/tok', ['Sec-Fetch-Dest' => 'empty', 'X-Checkout-Embed' => '1'])));
        // Navegador sem Fetch Metadata (sem suporte a CHIPS): comportamento antigo.
        $this->assertTrue(CheckoutEmbed::shouldPartitionSession($this->req('/c/abc123')));
    }

    public function test_painel_e_login_nunca_particionam(): void
    {
        $this->assertFalse(CheckoutEmbed::shouldPartitionSession($this->req('/login', ['X-Checkout-Embed' => '1'])));
        $this->assertFalse(CheckoutEmbed::shouldPartitionSession($this->req('/dashboard', ['Sec-Fetch-Dest' => 'iframe'])));
    }

    public function test_detecta_cookie_de_sessao_duplicado(): void
    {
        $session = (string) config('session.cookie');
        $dup = $this->req('/login', ['Cookie' => "XSRF-TOKEN=a; {$session}=velho; outra=1; {$session}=novo"]);
        $single = $this->req('/login', ['Cookie' => "XSRF-TOKEN=a; {$session}=novo; x{$session}=y"]);

        $this->assertSame([$session], CheckoutEmbed::duplicatedCookieNames($dup));
        $this->assertSame([], CheckoutEmbed::duplicatedCookieNames($single));
    }
}
