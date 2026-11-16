<?php

namespace Tests\Feature;

use App\Mail\CoproductionInvitationMail;
use App\Models\ProductCoproducer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Co-produção é aceita automaticamente: com conta, ativa ao adicionar; sem conta, ativa no cadastro.
 */
class CoproductionAutoAcceptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'global-user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
    }

    private function infoprodutor(string $email): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED, 'email' => $email]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function addCoproducer(User $seller, string $productId, string $email, float $pct = 30)
    {
        return $this->actingAs($seller)->post(route('produtos.coproducers.store', $productId), [
            'email' => $email,
            'commission_percent' => $pct,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
        ]);
    }

    public function test_email_de_conta_existente_fica_ativo_na_hora(): void
    {
        $seller = $this->infoprodutor('dono@test.com');
        $parceiro = $this->infoprodutor('parceiro@test.com');
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $this->addCoproducer($seller, (string) $product->id, 'Parceiro@Test.com')->assertSessionHasNoErrors();

        $row = ProductCoproducer::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(ProductCoproducer::STATUS_ACTIVE, $row->status);
        $this->assertSame((int) $parceiro->id, (int) $row->co_producer_user_id);
        $this->assertNotNull($row->accepted_at);
        $this->assertNull($row->ends_at, 'Duração eterna não tem fim.');
        Mail::assertSent(CoproductionInvitationMail::class, fn (CoproductionInvitationMail $m) => $m->autoAccepted && $m->hasTo('parceiro@test.com'));
    }

    public function test_email_sem_conta_fica_pendente_e_ativa_no_cadastro(): void
    {
        $seller = $this->infoprodutor('dono2@test.com');
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $this->addCoproducer($seller, (string) $product->id, 'novo@test.com')->assertSessionHasNoErrors();
        $row = ProductCoproducer::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(ProductCoproducer::STATUS_PENDING, $row->status);
        Mail::assertSent(CoproductionInvitationMail::class, fn (CoproductionInvitationMail $m) => ! $m->autoAccepted);

        // Cadastra sem usar o link do convite: ainda assim vira co-produção ativa.
        $novo = $this->infoprodutor('novo@test.com');
        $this->assertSame(1, ProductCoproducer::autoAcceptPendingFor($novo));
        $this->assertSame(ProductCoproducer::STATUS_ACTIVE, $row->fresh()->status);
        $this->assertSame((int) $novo->id, (int) $row->fresh()->co_producer_user_id);
    }

    public function test_conta_que_nao_e_infoprodutor_continua_bloqueada(): void
    {
        $seller = $this->infoprodutor('dono3@test.com');
        User::factory()->create(['role' => User::ROLE_ALUNO, 'email' => 'aluno@test.com']);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $this->addCoproducer($seller, (string) $product->id, 'aluno@test.com')->assertSessionHasErrors('email');
        $this->assertSame(0, ProductCoproducer::query()->where('product_id', $product->id)->count());
    }

    public function test_nao_ativa_para_o_proprio_dono_do_produto(): void
    {
        $seller = $this->infoprodutor('dono4@test.com');
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $row = ProductCoproducer::query()->create([
            'product_id' => $product->id, 'inviter_user_id' => $seller->id, 'email' => 'dono4@test.com',
            'status' => ProductCoproducer::STATUS_PENDING, 'token' => str_repeat('q', 48), 'commission_percent' => 10,
            'commission_on_direct_sales' => true, 'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
        ]);

        $this->assertSame(0, ProductCoproducer::autoAcceptPendingFor($seller));
        $this->assertSame(ProductCoproducer::STATUS_PENDING, $row->fresh()->status);
    }
}
