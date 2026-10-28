<?php

namespace Tests\Feature;

use App\Models\LandingPageVersion;
use App\Models\User;
use Tests\TestCase;

class LandingBlocksBuilderTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function save(User $seller, $product, array $landing)
    {
        return $this->actingAs($seller)->put(route('checkout.config.update', $product), [
            'config' => ['template' => 'landing', 'landing' => $landing],
        ]);
    }

    public function test_salvar_blocos_sanitiza_conteudo_e_registra_versao(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $this->save($seller, $product, [
            'mode' => 'blocks',
            'blocks' => [
                ['id' => 'hero-1', 'type' => 'hero', 'props' => ['title' => 'Curso X', 'button_action' => 'scroll_checkout']],
                ['id' => 'html-1', 'type' => 'html', 'props' => ['html' => '<img src=x onerror="alert(1)"><p>ok</p>', 'css' => 'body{position:fixed} .a{color:red}']],
                ['type' => 'checkout'],
                ['type' => 'malicioso', 'props' => []],
            ],
        ])->assertRedirect();

        $landing = $product->fresh()->checkout_config['landing'];
        $this->assertSame('blocks', $landing['mode']);
        $this->assertCount(3, $landing['blocks']);
        $html = $landing['blocks'][1]['props'];
        $this->assertStringNotContainsString('onerror', $html['html']);
        $this->assertStringNotContainsString('position:fixed', $html['css_scoped']);
        $this->assertStringStartsWith('.lpb-html-1', $html['css_scoped']);
        $this->assertSame(1, LandingPageVersion::query()->where('product_id', (string) $product->id)->count());
    }

    public function test_bloco_removido_nao_volta_no_proximo_salvamento(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $three = [
            ['id' => 'aaaa1', 'type' => 'heading', 'props' => ['text' => 'A']],
            ['id' => 'bbbb2', 'type' => 'heading', 'props' => ['text' => 'B']],
            ['id' => 'cccc3', 'type' => 'heading', 'props' => ['text' => 'C']],
        ];
        $this->save($seller, $product, ['mode' => 'blocks', 'blocks' => $three]);
        $this->save($seller, $product, ['mode' => 'blocks', 'blocks' => [$three[2]]]);

        $blocks = $product->fresh()->checkout_config['landing']['blocks'];
        $this->assertCount(1, $blocks);
        $this->assertSame('C', $blocks[0]['props']['text']);
        $this->assertSame(2, LandingPageVersion::query()->where('product_id', (string) $product->id)->count());
    }

    public function test_versoes_so_para_o_dono_do_produto(): void
    {
        $owner = $this->seller();
        $intruder = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $owner->id]);
        $this->save($owner, $product, ['mode' => 'blocks', 'blocks' => [['id' => 'aaaa1', 'type' => 'heading', 'props' => ['text' => 'V1']]]]);
        $version = LandingPageVersion::query()->where('product_id', (string) $product->id)->firstOrFail();

        $this->actingAs($owner)->getJson("/produtos/{$product->id}/landing/versoes")
            ->assertOk()->assertJsonCount(1, 'versions');
        $this->actingAs($owner)->getJson("/produtos/{$product->id}/landing/versoes/{$version->id}")
            ->assertOk()->assertJsonPath('landing.blocks.0.props.text', 'V1');

        $this->actingAs($intruder)->getJson("/produtos/{$product->id}/landing/versoes")->assertForbidden();
        $this->actingAs($intruder)->getJson("/produtos/{$product->id}/landing/versoes/{$version->id}")->assertForbidden();
    }

    public function test_checkout_publico_entrega_os_blocos_publicados(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->save($seller, $product, [
            'mode' => 'blocks',
            'blocks' => [
                ['id' => 'hero-1', 'type' => 'hero', 'props' => ['title' => 'Página publicada']],
                ['type' => 'checkout'],
            ],
        ]);

        $this->get('/c/'.$product->fresh()->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('checkout_config.landing.mode', 'blocks')
                ->where('checkout_config.landing.blocks.0.props.title', 'Página publicada'));
    }

    public function test_produto_antigo_sem_modo_continua_em_secoes(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->save($seller, $product, ['headline' => 'Antigo']);

        $landing = $product->fresh()->checkout_config['landing'];
        $this->assertSame('sections', $landing['mode']);
        $this->assertSame('Antigo', $landing['headline']);
    }
}
