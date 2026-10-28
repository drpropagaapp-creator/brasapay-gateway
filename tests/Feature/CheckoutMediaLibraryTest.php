<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CheckoutMediaLibraryTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    public function test_lista_so_imagens_do_produto_e_bloqueia_outra_conta(): void
    {
        Storage::fake('public');
        $owner = $this->seller();
        $intruder = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $owner->id]);
        $other = $this->createTestProduct(['tenant_id' => $owner->id]);

        Storage::disk('public')->put("checkout/{$product->id}/banner.png", 'x');
        Storage::disk('public')->put("checkout/{$product->id}/imported/capa.webp", 'x');
        Storage::disk('public')->put("checkout/{$product->id}/notas.txt", 'x');
        Storage::disk('public')->put("checkout/{$other->id}/alheia.png", 'x');

        $res = $this->actingAs($owner)->getJson("/produtos/{$product->id}/midias")->assertOk();
        $names = collect($res->json('items'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['banner.png', 'capa.webp'], $names);

        $this->actingAs($intruder)->getJson("/produtos/{$product->id}/midias")->assertForbidden();
    }

    public function test_apagar_recusa_imagem_em_uso_e_caminho_de_outro_produto(): void
    {
        Storage::fake('public');
        $owner = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $owner->id]);
        $other = $this->createTestProduct(['tenant_id' => $owner->id]);
        $disk = Storage::disk('public');
        $disk->put("checkout/{$product->id}/em-uso.png", 'x');
        $disk->put("checkout/{$product->id}/livre.png", 'x');
        $disk->put("checkout/{$other->id}/alheia.png", 'x');

        $config = $product->checkout_config ?? [];
        $config['banner'] = $disk->url("checkout/{$product->id}/em-uso.png");
        $product->forceFill(['checkout_config' => $config])->save();

        $this->actingAs($owner)->deleteJson("/produtos/{$product->id}/midias", ['path' => "checkout/{$product->id}/em-uso.png"])
            ->assertStatus(422);
        $this->assertTrue($disk->exists("checkout/{$product->id}/em-uso.png"));

        $this->actingAs($owner)->deleteJson("/produtos/{$product->id}/midias", ['path' => "checkout/{$other->id}/alheia.png"])
            ->assertForbidden();
        $this->actingAs($owner)->deleteJson("/produtos/{$product->id}/midias", ['path' => "checkout/{$product->id}/../{$other->id}/alheia.png"])
            ->assertForbidden();
        $this->assertTrue($disk->exists("checkout/{$other->id}/alheia.png"));

        $this->actingAs($owner)->deleteJson("/produtos/{$product->id}/midias", ['path' => "checkout/{$product->id}/livre.png"])
            ->assertOk();
        $this->assertFalse($disk->exists("checkout/{$product->id}/livre.png"));
    }
}
