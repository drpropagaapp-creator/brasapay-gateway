<?php

namespace Tests\Feature;

use App\Models\EnterpriseAccount;
use App\Models\EnterpriseDomain;
use App\Models\User;
use App\Services\Enterprise\DomainProbe;
use App\Services\Enterprise\EnterpriseService;
use Tests\TestCase;

/** BrasaPay 4.0 / Onda 3 — Brasa Enterprise (white-label). */
class BrasaEnterpriseTest extends TestCase
{
    /** DNS e HTTPS simulados (sem rede). */
    private object $probe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://app.brasapay.test']);
        $this->probe = new class extends DomainProbe
        {
            public array $txt = [];

            public bool $https = true;

            public function txtRecords(string $name): array
            {
                return $this->txt[$name] ?? [];
            }

            public function httpsServesToken(string $host, string $token): bool
            {
                return $this->https;
            }
        };
        $this->app->instance(DomainProbe::class, $this->probe);
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

    private function activeDomain(User $seller, string $host = 'pagar.cliente.com.br'): EnterpriseDomain
    {
        $svc = app(EnterpriseService::class);
        $svc->enable($seller->id, 1, 'Cliente SA');
        $d = $svc->addDomain($seller->id, $host);
        $this->probe->txt[$d->txtName()] = [$d->txtValue()];

        return $svc->verify($d);
    }

    public function test_so_admin_ativa_e_dominios_sao_validados(): void
    {
        $seller = $this->seller();
        $this->assertNotSame(200, $this->actingAs($seller)->get('/plataforma/enterprise')->status());
        $this->actingAs($seller)->post('/marca-e-dominio/dominios', ['host' => 'pagar.cliente.com.br'])->assertForbidden();

        $this->actingAs($this->admin())->post('/plataforma/enterprise', ['seller' => $seller->email])->assertRedirect();
        $this->assertTrue(EnterpriseAccount::query()->where('tenant_id', $seller->id)->first()->isActive());

        foreach (['app.brasapay.test', 'loja.app.brasapay.test', '10.0.0.1', 'localhost', 'sem-ponto'] as $bad) {
            $this->actingAs($seller)->post('/marca-e-dominio/dominios', ['host' => $bad])->assertSessionHasErrors('host');
        }
        $this->actingAs($seller)->post('/marca-e-dominio/dominios', ['host' => 'https://Pagar.Cliente.com.br/qualquer'])->assertSessionHasNoErrors();
        $this->assertSame('pagar.cliente.com.br', EnterpriseDomain::query()->value('host'));
        $this->actingAs($this->seller())->post('/marca-e-dominio/dominios', ['host' => 'pagar.cliente.com.br'])->assertForbidden();
    }

    public function test_ativa_so_com_txt_e_https(): void
    {
        $seller = $this->seller();
        $svc = app(EnterpriseService::class);
        $svc->enable($seller->id, 1, null);
        $d = $svc->addDomain($seller->id, 'pagar.cliente.com.br');

        $this->assertSame(EnterpriseDomain::STATUS_PENDING, $svc->verify($d)->status, 'Sem TXT.');
        $this->probe->txt[$d->txtName()] = [$d->txtValue()];
        $this->probe->https = false;
        $this->assertSame(EnterpriseDomain::STATUS_PENDING, $svc->verify($d)->status, 'Sem HTTPS até a plataforma.');
        $this->probe->https = true;
        $this->assertSame(EnterpriseDomain::STATUS_ACTIVE, $svc->verify($d)->status);
    }

    public function test_dominio_serve_so_o_checkout_do_proprio_seller(): void
    {
        $seller = $this->seller();
        $own = $this->createTestProduct(['tenant_id' => $seller->id, 'checkout_slug' => 'meuslug1']);
        $other = $this->createTestProduct(['tenant_id' => $this->seller()->id, 'checkout_slug' => 'outroslug']);
        $this->activeDomain($seller);
        app(EnterpriseService::class)->updateBranding($seller->id, ['app_name' => 'Cliente Pagamentos']);

        $base = 'http://pagar.cliente.com.br';
        $this->get($base.'/login')->assertNotFound();
        $this->get($base.'/dashboard')->assertNotFound();
        $this->get($base.'/plataforma/login')->assertNotFound();
        $this->get($base.'/c/'.$other->checkout_slug)->assertNotFound();
        $this->assertNotSame(404, $this->get($base.'/c/'.$own->checkout_slug)->status());
        $this->assertSame('Cliente Pagamentos', config('getfy.app_name'), 'Checkout no domínio usa a marca da conta.');
    }

    public function test_dominio_pendente_suspenso_ou_que_perdeu_dns_nao_serve_nada(): void
    {
        $seller = $this->seller();
        $svc = app(EnterpriseService::class);
        $svc->enable($seller->id, 1, null);
        $pending = $svc->addDomain($seller->id, 'novo.cliente.com.br');
        $this->get('http://novo.cliente.com.br/login')->assertNotFound();
        $this->get('http://novo.cliente.com.br/.well-known/brasapay-domain/'.$pending->verification_token)->assertOk()->assertSee($pending->verification_token);

        $d = $this->activeDomain($seller, 'pagar.cliente.com.br');
        $slug = $this->createTestProduct(['tenant_id' => $seller->id, 'checkout_slug' => 'meuslug2'])->checkout_slug;
        $svc->setSuspended(EnterpriseAccount::query()->where('tenant_id', $seller->id)->first(), true, 'Inadimplência contratual');
        $this->get('http://pagar.cliente.com.br/c/'.$slug)->assertNotFound();
        $svc->setSuspended(EnterpriseAccount::query()->where('tenant_id', $seller->id)->first(), false, 'Regularizado');

        $this->probe->txt = [];
        foreach (range(1, EnterpriseDomain::MAX_FAILURES) as $i) {
            $svc->recheck($d->fresh());
        }
        $this->assertSame(EnterpriseDomain::STATUS_FAILED, $d->fresh()->status);
        $this->get('http://pagar.cliente.com.br/c/'.$slug)->assertNotFound();
    }

    public function test_equipe_e_outro_seller_nao_mexem_no_dominio(): void
    {
        $seller = $this->seller();
        $d = $this->activeDomain($seller);
        $this->actingAs($this->seller())->post("/marca-e-dominio/dominios/{$d->id}/verificar")->assertForbidden();
        $this->actingAs($seller)->get('/marca-e-dominio')->assertOk();
        $this->actingAs($seller)->delete("/marca-e-dominio/dominios/{$d->id}")->assertRedirect();
        $this->assertSame(EnterpriseDomain::STATUS_REMOVED, $d->fresh()->status);
    }
}
