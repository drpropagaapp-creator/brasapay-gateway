<?php

namespace Tests\Feature;

use App\Jobs\ImportProductPackageJob;
use App\Models\MarketplaceInstall;
use App\Models\MarketplaceReview;
use App\Models\MarketplaceTemplate;
use App\Models\MarketplaceTemplateVersion;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\ProductCoproducer;
use App\Models\ProductImport;
use App\Models\User;
use App\Services\ProductPortability\PackageReader;
use App\Support\RecoveryEmailSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** BrasaPay 4.0 / Onda 3 — Brasa Marketplace (biblioteca de templates). */
class BrasaMarketplaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
    }

    /** Produto com aula paga e coprodutor: nada disso pode ir para o template. */
    private function productWithPrivateContent(User $seller): Product
    {
        $p = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Kit Lançamento']);
        $section = MemberSection::query()->create(['product_id' => $p->id, 'title' => 'Módulos', 'position' => 0]);
        $module = MemberModule::query()->create(['member_section_id' => $section->id, 'product_id' => $p->id, 'title' => 'Módulo 1', 'position' => 0]);
        MemberLesson::query()->create(['member_module_id' => $module->id, 'product_id' => $p->id, 'title' => 'Aula secreta', 'position' => 0, 'type' => 'text', 'content_text' => 'conteúdo pago']);
        ProductCoproducer::query()->create([
            'product_id' => $p->id, 'inviter_user_id' => $seller->id, 'email' => 'co@x.com', 'status' => ProductCoproducer::STATUS_ACTIVE,
            'token' => str_repeat('y', 48), 'commission_percent' => 30, 'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false, 'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
        ]);

        return $p;
    }

    private function submitAndApprove(User $author, Product $product): MarketplaceTemplate
    {
        $this->actingAs($author)->post('/biblioteca/enviar', [
            'kind' => 'product_package', 'product_id' => (string) $product->id, 'title' => 'Kit Lançamento', 'category' => 'kit', 'confirm_public' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $version = MarketplaceTemplateVersion::query()->latest('id')->firstOrFail();
        $this->actingAs($this->admin())->post("/plataforma/biblioteca/versoes/{$version->id}/revisar", ['decision' => 'approve'])->assertRedirect();

        return MarketplaceTemplate::query()->findOrFail($version->template_id);
    }

    public function test_template_nao_leva_aulas_nem_coproducao_e_so_aparece_depois_de_aprovado(): void
    {
        $author = $this->seller();
        $product = $this->productWithPrivateContent($author);
        $this->actingAs($author)->post('/biblioteca/enviar', [
            'kind' => 'product_package', 'product_id' => (string) $product->id, 'title' => 'Kit Lançamento', 'category' => 'kit', 'confirm_public' => '1',
        ])->assertRedirect();

        $version = MarketplaceTemplateVersion::query()->firstOrFail();
        $reader = PackageReader::open($version->absolutePackagePath());
        $data = $reader->data();
        $reader->close();
        $this->assertSame([], $data['products'][0]['member_area']['sections'], 'Aulas não vão para o template.');
        $this->assertSame([], $data['products'][0]['coproduction_template'], 'Coprodução não vai para o template.');
        $this->assertStringNotContainsString('conteúdo pago', json_encode($data));

        $buyer = $this->seller();
        $template = MarketplaceTemplate::query()->firstOrFail();
        $this->actingAs($buyer)->get("/biblioteca/{$template->slug}")->assertNotFound();
        $this->actingAs($buyer)->post("/biblioteca/{$template->slug}/instalar")->assertNotFound();

        $this->actingAs($this->admin())->post("/plataforma/biblioteca/versoes/{$version->id}/revisar", ['decision' => 'approve']);
        $this->actingAs($buyer)->get("/biblioteca/{$template->slug}")->assertOk();
    }

    public function test_instala_pelo_importador_oficial_com_confirmacao(): void
    {
        Queue::fake();
        $author = $this->seller();
        $template = $this->submitAndApprove($author, $this->productWithPrivateContent($author));
        $buyer = $this->seller();
        $before = Product::query()->where('tenant_id', $buyer->id)->count();

        $res = $this->actingAs($buyer)->post("/biblioteca/{$template->slug}/instalar");
        $import = ProductImport::query()->where('tenant_id', $buyer->id)->firstOrFail();
        $res->assertRedirect(route('produtos.import.show', $import));
        $this->assertSame(ProductImport::STATUS_READY, $import->status);
        $this->assertSame($before, Product::query()->where('tenant_id', $buyer->id)->count(), 'Nada é criado antes da confirmação.');
        $this->assertSame(1, MarketplaceInstall::query()->where('tenant_id', $buyer->id)->count());

        $this->actingAs($buyer)->post(route('produtos.import.confirm', $import));
        Queue::assertPushed(ImportProductPackageJob::class);
        (new ImportProductPackageJob($import->id))->handle(app(\App\Services\ProductPortability\ProductImporter::class));
        $new = Product::query()->where('tenant_id', $buyer->id)->where('name', 'Kit Lançamento')->firstOrFail();
        $this->assertSame(0, ProductCoproducer::query()->where('product_id', $new->id)->count());
        $this->assertSame(0, MemberLesson::query()->where('product_id', $new->id)->count());
    }

    public function test_pacote_adulterado_nao_instala(): void
    {
        $author = $this->seller();
        $template = $this->submitAndApprove($author, $this->createTestProduct(['tenant_id' => $author->id]));
        $version = MarketplaceTemplateVersion::query()->findOrFail($template->current_version_id);
        Storage::disk('local')->append($version->package_path, 'lixo');

        $this->actingAs($this->seller())->post("/biblioteca/{$template->slug}/instalar")->assertStatus(422);
        $this->assertSame(0, ProductImport::query()->count());
    }

    public function test_recuperacao_instala_textos_sem_ligar_e_avaliacao_moderada(): void
    {
        $author = $this->seller();
        $settings = RecoveryEmailSettings::defaults();
        $settings['events']['pix_pending']['steps'][0]['subject'] = 'Assunto do autor';
        RecoveryEmailSettings::save($author->id, $settings);
        $this->actingAs($author)->post('/biblioteca/enviar', ['kind' => 'recovery', 'title' => 'Recuperação que converte', 'confirm_public' => '1'])->assertRedirect();
        $version = MarketplaceTemplateVersion::query()->firstOrFail();
        $admin = $this->admin();
        $this->actingAs($admin)->post("/plataforma/biblioteca/versoes/{$version->id}/revisar", ['decision' => 'approve']);
        $template = MarketplaceTemplate::query()->firstOrFail();

        $buyer = $this->seller();
        $this->actingAs($buyer)->post("/biblioteca/{$template->slug}/avaliar", ['rating' => 5])->assertForbidden();
        $this->actingAs($buyer)->post("/biblioteca/{$template->slug}/instalar")->assertRedirect('/recuperacao');
        $mine = RecoveryEmailSettings::forTenant($buyer->id);
        $this->assertSame('Assunto do autor', $mine['events']['pix_pending']['steps'][0]['subject']);
        $this->assertFalse($mine['enabled'], 'Instalar não liga a recuperação.');

        $this->actingAs($buyer)->post("/biblioteca/{$template->slug}/avaliar", ['rating' => 4, 'comment' => 'Bom'])->assertRedirect();
        $this->assertNull($template->fresh()->ratingAverage(), 'Só conta depois da moderação.');
        $review = MarketplaceReview::query()->firstOrFail();
        $this->actingAs($admin)->post("/plataforma/biblioteca/avaliacoes/{$review->id}", ['decision' => 'approve']);
        $this->assertSame(4.0, $template->fresh()->ratingAverage());
        $this->actingAs($admin)->post("/plataforma/biblioteca/avaliacoes/{$review->id}", ['decision' => 'reject']);
        $this->assertNull($template->fresh()->ratingAverage());
    }

    public function test_template_de_recuperacao_nao_aceita_link_proprio(): void
    {
        $author = $this->seller();
        $settings = RecoveryEmailSettings::defaults();
        $settings['events']['pix_pending']['steps'][0]['body'] = 'Pague aqui: https://golpe.example/pix {link}';
        RecoveryEmailSettings::save($author->id, $settings);

        $this->actingAs($author)->post('/biblioteca/enviar', ['kind' => 'recovery', 'title' => 'Textos', 'confirm_public' => '1'])->assertSessionHasErrors('kind');
        $this->assertSame(0, MarketplaceTemplate::query()->count());
    }

    public function test_equipe_e_seller_comum_nao_administram(): void
    {
        $seller = $this->seller();
        $this->assertNotSame(200, $this->actingAs($seller)->get('/plataforma/biblioteca')->status());
        $this->actingAs($this->admin())->get('/plataforma/biblioteca')->assertOk();
        $this->actingAs($seller)->get('/biblioteca')->assertOk();
    }
}
