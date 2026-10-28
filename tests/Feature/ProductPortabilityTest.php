<?php

namespace Tests\Feature;

use App\Jobs\ImportProductPackageJob;
use App\Models\Product;
use App\Models\ProductCoproducer;
use App\Models\ProductImport;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ProductPortability\PackageException;
use App\Services\ProductPortability\PackageReader;
use App\Services\ProductPortability\ProductExporter;
use App\Services\ProductPortability\ProductImporter;
use App\Services\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ProductPortabilityTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    /** Produto completo: oferta, plano, bump para outro produto, landing com imagem própria, coprodução. */
    private function fullProduct(User $seller): array
    {
        $bumpTarget = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Bônus Planilha']);
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Curso Escala']);

        $storage = new StorageService($seller->id);
        $path = "checkout/{$product->id}/hero.png";
        Storage::disk('public')->put($path, base64_decode(self::PNG));
        $heroUrl = $storage->url($path);

        $product->forceFill(['checkout_config' => [
            'template' => 'landing',
            'payment_gateways' => ['pix' => 'cajupay'],
            'landing' => ['mode' => 'blocks', 'blocks' => [
                ['id' => 'hero-1', 'type' => 'hero', 'props' => ['title' => 'Curso Escala', 'image' => $heroUrl]],
                ['type' => 'checkout'],
            ]],
        ]])->save();

        ProductOffer::query()->create(['product_id' => $product->id, 'name' => 'Oferta 297', 'price' => 297, 'checkout_slug' => ProductOffer::generateUniqueCheckoutSlug(), 'checkout_config' => [], 'position' => 0]);
        SubscriptionPlan::query()->create(['product_id' => $product->id, 'name' => 'Mensal', 'price' => 49.9, 'interval' => 'monthly', 'checkout_slug' => SubscriptionPlan::generateUniqueCheckoutSlug(), 'checkout_config' => [], 'position' => 0, 'gateway_plan_id' => 'price_secreto']);
        ProductOrderBump::query()->create(['product_id' => $product->id, 'target_product_id' => $bumpTarget->id, 'title' => 'Leve a planilha', 'cta_title' => 'Sim, quero', 'position' => 0]);
        ProductCoproducer::query()->create([
            'product_id' => $product->id, 'inviter_user_id' => $seller->id, 'email' => 'co@x.com',
            'status' => ProductCoproducer::STATUS_PENDING, 'token' => str_repeat('z', 48), 'commission_percent' => 20,
            'commission_on_direct_sales' => true, 'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
        ]);

        return [$product->fresh(), $bumpTarget, $heroUrl];
    }

    private function importInto(string $zipPath, User $target): array
    {
        $reader = PackageReader::open($zipPath);
        try {
            return app(ProductImporter::class)->import($reader, (int) $target->id, $target->id);
        } finally {
            $reader->close();
        }
    }

    public function test_cenario_1_seller_a_exporta_e_seller_b_importa_tudo_com_ids_novos(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        [$product, $bumpTarget, $heroUrl] = $this->fullProduct($a);

        $zip = app(ProductExporter::class)->export($product, [(string) $bumpTarget->id]);
        $result = $this->importInto($zip, $b);

        $this->assertCount(2, $result['products']);
        $new = Product::query()->findOrFail($result['products'][0]['id']);
        $newTarget = Product::query()->findOrFail($result['products'][1]['id']);

        $this->assertSame((int) $b->id, (int) $new->tenant_id);
        $this->assertNotEquals((string) $product->id, (string) $new->id);
        $this->assertNotSame($product->checkout_slug, $new->checkout_slug);
        $this->assertSame('Curso Escala', $new->name);
        $this->assertFalse((bool) $new->is_active, 'Importado entra inativo, para revisão na conta de destino.');

        $cfg = $new->getRawOriginal('checkout_config');
        $cfg = is_string($cfg) ? json_decode($cfg, true) : $cfg;
        $this->assertArrayNotHasKey('payment_gateways', $cfg, 'Preferência de adquirente não viaja.');
        $newHero = $cfg['landing']['blocks'][0]['props']['image'];
        $this->assertNotSame($heroUrl, $newHero);
        $this->assertStringContainsString("checkout/{$new->id}/imported/", $newHero);
        $this->assertTrue(Storage::disk('public')->exists((new StorageService($b->id))->toStoragePath($newHero)));

        $this->assertSame(1, ProductOffer::query()->where('product_id', $new->id)->count());
        $plan = SubscriptionPlan::query()->where('product_id', $new->id)->firstOrFail();
        $this->assertNull($plan->gateway_plan_id);
        $bump = ProductOrderBump::query()->where('product_id', $new->id)->firstOrFail();
        $this->assertSame((string) $newTarget->id, (string) $bump->target_product_id, 'Bump aponta para o produto recriado em B, nunca para o de A.');

        $this->assertSame(0, ProductCoproducer::query()->where('product_id', $new->id)->count(), 'Coprodução exige novo convite/aceite.');
        $this->assertNotEmpty(array_filter($result['warnings'], fn ($w) => str_contains($w, 'coprodução')));
    }

    public function test_sem_dependencia_o_bump_nao_aponta_para_a_conta_de_origem(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        [$product] = $this->fullProduct($a);

        $zip = app(ProductExporter::class)->export($product);
        $result = $this->importInto($zip, $b);

        $new = $result['products'][0]['id'];
        $this->assertSame(0, ProductOrderBump::query()->where('product_id', $new)->count());
        $this->assertNotEmpty(array_filter($result['warnings'], fn ($w) => str_contains($w, 'Order bump')));
    }

    public function test_dependencia_de_outro_tenant_e_ignorada_no_export(): void
    {
        $a = $this->seller();
        $other = $this->seller();
        [$product] = $this->fullProduct($a);
        $foreign = $this->createTestProduct(['tenant_id' => $other->id, 'name' => 'Alheio']);

        $zip = app(ProductExporter::class)->export($product, [(string) $foreign->id]);
        $reader = PackageReader::open($zip);
        $names = array_column($reader->manifest()['products'], 'name');
        $reader->close();

        $this->assertNotContains('Alheio', $names);
    }

    public function test_arquivo_de_outro_produto_citado_na_config_nao_entra_no_pacote(): void
    {
        $a = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $a->id]);
        $alheio = $this->createTestProduct(['tenant_id' => $this->seller()->id]);
        $foreignPath = "checkout/{$alheio->id}/secreto.png";
        Storage::disk('public')->put($foreignPath, base64_decode(self::PNG));
        $foreignUrl = (new StorageService($a->id))->url($foreignPath);
        $product->forceFill(['checkout_config' => ['seo' => ['og_image' => $foreignUrl]]])->save();

        $zip = app(ProductExporter::class)->export($product->fresh());
        $reader = PackageReader::open($zip);
        $assets = $reader->assetNames();
        $reader->close();

        $this->assertSame([], $assets);
    }

    public function test_seller_nao_exporta_produto_de_outro_seller(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $a->id]);

        $this->actingAs($b)->post("/produtos/{$product->id}/exportar")->assertForbidden();
        $this->actingAs($b)->getJson("/produtos/{$product->id}/exportar/opcoes")->assertForbidden();
    }

    public function test_fluxo_http_upload_previa_confirmacao_e_job_idempotente(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        [$product] = $this->fullProduct($a);
        $zip = app(ProductExporter::class)->export($product);

        $this->actingAs($b)->post('/produtos/importar', [
            'package' => new UploadedFile($zip, 'curso.brasapay.zip', 'application/zip', null, true),
        ])->assertRedirect();

        $import = ProductImport::query()->where('tenant_id', $b->id)->latest('id')->firstOrFail();
        $this->assertSame(ProductImport::STATUS_READY, $import->status);
        $this->assertSame('Curso Escala', $import->summary['products'][0]['name']);

        $this->actingAs($b)->post("/produtos/importar/{$import->uuid}/confirmar")->assertRedirect();
        $this->actingAs($b)->post("/produtos/importar/{$import->uuid}/confirmar")->assertRedirect();
        (new ImportProductPackageJob($import->id))->handle(app(ProductImporter::class));

        $this->assertSame(ProductImport::STATUS_COMPLETED, $import->fresh()->status);
        $this->assertSame(1, Product::query()->where('tenant_id', $b->id)->where('name', 'Curso Escala')->count());
    }

    public function test_pagina_de_importacao_abre_para_o_titular(): void
    {
        $this->actingAs($this->seller())->get('/produtos/importar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Produtos/Importar'));
    }

    public function test_importacao_de_outro_tenant_e_invisivel(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        [$product] = $this->fullProduct($a);
        $zip = app(ProductExporter::class)->export($product);
        $this->actingAs($b)->post('/produtos/importar', ['package' => new UploadedFile($zip, 'x.zip', 'application/zip', null, true)]);
        $import = ProductImport::query()->where('tenant_id', $b->id)->firstOrFail();

        $this->actingAs($a)->get("/produtos/importar/{$import->uuid}")->assertNotFound();
        $this->actingAs($a)->post("/produtos/importar/{$import->uuid}/confirmar")->assertNotFound();
    }

    /** Copia o pacote aplicando uma alteração e devolve o caminho. */
    private function tamper(string $zipPath, callable $mutate): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'tamper');
        copy($zipPath, $copy);
        $zip = new ZipArchive;
        $zip->open($copy);
        $mutate($zip);
        $zip->close();

        return $copy;
    }

    private function assertRejected(string $path, string $why): void
    {
        try {
            PackageReader::open($path)->close();
            $this->fail("Pacote deveria ser rejeitado: {$why}");
        } catch (PackageException) {
            $this->assertTrue(true);
        }
    }

    public function test_pacotes_maliciosos_ou_adulterados_sao_rejeitados(): void
    {
        $a = $this->seller();
        [$product] = $this->fullProduct($a);
        $zip = app(ProductExporter::class)->export($product);

        $this->assertRejected($this->tamper($zip, fn ($z) => $z->addFromString('../../evil.php', '<?php echo 1;')), 'path traversal');
        $this->assertRejected($this->tamper($zip, fn ($z) => $z->addFromString('assets/'.str_repeat('a', 64).'.php', 'x')), 'extensão proibida');
        $this->assertRejected($this->tamper($zip, function ($z) {
            $m = json_decode($z->getFromName('manifest.json'), true);
            $m['format'] = 'outra-coisa';
            $z->addFromString('manifest.json', json_encode($m));
        }), 'manifesto adulterado');
        $this->assertRejected($this->tamper($zip, function ($z) {
            $d = json_decode($z->getFromName('data/package.json'), true);
            $d['products'][0]['fields']['price'] = '0.01';
            $z->addFromString('data/package.json', json_encode($d));
        }), 'dados alterados depois de gerados');
        $this->assertRejected($this->tamper($zip, fn ($z) => $z->addFromString('assets/'.hash('sha256', 'x').'.png', 'x')), 'arquivo fora do manifesto');
        $this->assertRejected($this->tamper($zip, fn ($z) => $z->addFromString('data/'.str_repeat('a', 3).'.json', '{}')), 'arquivo de dados não previsto');

        $bomb = tempnam(sys_get_temp_dir(), 'bomb');
        $z = new ZipArchive;
        $z->open($bomb, ZipArchive::OVERWRITE);
        $z->addFromString('manifest.json', '{}');
        $z->addFromString('data/package.json', str_repeat('A', 20 * 1024 * 1024));
        $z->close();
        $this->assertRejected($bomb, 'zip bomb (taxa de compressão)');
    }

    public function test_midia_trocada_no_pacote_e_detectada_na_importacao(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        [$product] = $this->fullProduct($a);
        $zip = app(ProductExporter::class)->export($product);

        $reader = PackageReader::open($zip);
        $asset = $reader->assetNames()[0];
        $reader->close();
        $bad = $this->tamper($zip, fn ($z) => $z->addFromString('assets/'.$asset, base64_decode(self::PNG).'X'));

        try {
            $this->importInto($bad, $b);
            $this->fail('Mídia adulterada deveria abortar a importação.');
        } catch (PackageException) {
            $this->assertSame(0, Product::query()->where('tenant_id', $b->id)->count(), 'Nada criado: a transação foi desfeita.');
        }
    }
}
