<?php

namespace Tests\Feature;

use App\Models\PlatformAuditLog;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\User;
use App\Services\ProductPortability\ProductExporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformSellerPortabilityTest extends TestCase
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

    public function test_admin_exporta_produto_do_seller_com_motivo_e_auditoria(): void
    {
        $admin = $this->admin();
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Curso Admin']);

        $this->actingAs($admin)->getJson("/plataforma/usuarios/{$seller->id}/portabilidade")
            ->assertOk()->assertJsonPath('products.0.name', 'Curso Admin');

        $this->actingAs($admin)->postJson("/plataforma/usuarios/{$seller->id}/portabilidade/produtos/{$product->id}/exportar", [])
            ->assertStatus(422);

        $res = $this->actingAs($admin)->post("/plataforma/usuarios/{$seller->id}/portabilidade/produtos/{$product->id}/exportar", [
            'reason' => 'Migração pedida no ticket 42',
        ]);
        $res->assertOk();
        $this->assertStringContainsString('curso-admin.brasapay.zip', (string) $res->headers->get('content-disposition'));

        $log = PlatformAuditLog::query()->where('action', 'platform.seller.product_exported')->firstOrFail();
        $this->assertSame((int) $admin->id, (int) $log->user_id);
        $this->assertSame((string) $product->id, $log->metadata['product_id']);
        $this->assertSame('Migração pedida no ticket 42', $log->metadata['reason']);
    }

    public function test_produto_de_outro_seller_e_seller_comum_sao_barrados(): void
    {
        $admin = $this->admin();
        $seller = $this->seller();
        $other = $this->seller();
        $foreign = $this->createTestProduct(['tenant_id' => $other->id]);

        $this->actingAs($admin)->postJson("/plataforma/usuarios/{$seller->id}/portabilidade/produtos/{$foreign->id}/exportar", [
            'reason' => 'Tentativa cruzada',
        ])->assertNotFound();

        $this->actingAs($seller)->getJson("/plataforma/usuarios/{$other->id}/portabilidade")
            ->assertStatus(403);
        $this->assertSame(0, PlatformAuditLog::query()->where('action', 'platform.seller.product_exported')->count());
    }

    public function test_admin_importa_pacote_na_conta_do_seller(): void
    {
        $admin = $this->admin();
        $source = $this->seller();
        $target = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $source->id, 'name' => 'Curso Migrado']);
        $zip = app(ProductExporter::class)->export($product);

        $upload = $this->actingAs($admin)->post("/plataforma/usuarios/{$target->id}/portabilidade/importacoes", [
            'package' => new UploadedFile($zip, 'curso.brasapay.zip', 'application/zip', null, true),
        ], ['Accept' => 'application/json']);
        $upload->assertOk()->assertJsonPath('status', ProductImport::STATUS_READY);
        $uuid = $upload->json('uuid');

        $this->actingAs($admin)->postJson("/plataforma/usuarios/{$target->id}/portabilidade/importacoes/{$uuid}/confirmar", [])
            ->assertStatus(422);

        // Outro seller na URL não enxerga a importação.
        $this->actingAs($admin)->postJson("/plataforma/usuarios/{$source->id}/portabilidade/importacoes/{$uuid}/confirmar", [
            'reason' => 'Rota trocada',
        ])->assertNotFound();

        $this->actingAs($admin)->postJson("/plataforma/usuarios/{$target->id}/portabilidade/importacoes/{$uuid}/confirmar", [
            'reason' => 'Migração entre contas do mesmo cliente',
        ])->assertOk();

        $import = ProductImport::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ProductImport::STATUS_COMPLETED, $import->status);
        $created = Product::query()->where('tenant_id', $target->id)->where('name', 'Curso Migrado')->first();
        $this->assertNotNull($created);
        $this->assertFalse((bool) $created->is_active);
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'platform.seller.product_imported')->count());

        // Reenvio da confirmação não importa de novo.
        $this->actingAs($admin)->postJson("/plataforma/usuarios/{$target->id}/portabilidade/importacoes/{$uuid}/confirmar", [
            'reason' => 'Clique duplo',
        ])->assertOk();
        $this->assertSame(1, Product::query()->where('tenant_id', $target->id)->where('name', 'Curso Migrado')->count());
    }
}
