<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\SellerAcquirerPolicy;
use App\Models\SellerAcquirerPolicyLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\AcquirerPolicyService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SellerAcquirerPolicyTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function connect(array $slugs): void
    {
        $creds = [
            'cajupay' => ['public_key' => 'pk', 'secret_key' => 'sk'],
            'mercadopago' => ['public_key' => 'pk', 'access_token' => 'TEST'],
            'woovi' => ['app_id' => 'app'],
            'efi' => ['client_id' => 'id', 'client_secret' => 'secret'],
            'stripe' => ['secret_key' => 'sk_test', 'publishable_key' => 'pk_test'],
        ];
        foreach ($slugs as $slug) {
            $cred = GatewayCredential::query()->firstOrNew(['tenant_id' => null, 'gateway_slug' => $slug]);
            $cred->is_connected = true;
            $cred->setEncryptedCredentials($creds[$slug]);
            $cred->save();
        }
    }

    private function policy(): AcquirerPolicyService
    {
        return app(AcquirerPolicyService::class);
    }

    private function freshPolicy(): void
    {
        $this->app->forgetScopedInstances();
    }

    public function test_seller_sem_politica_mantem_a_ordem_atual(): void
    {
        $seller = $this->seller();
        Setting::set('gateway_order', ['pix' => ['mercadopago', 'cajupay']], null);

        $order = app(PaymentService::class)->getGatewayOrderForMethod($seller->id, 'pix');

        $this->assertSame('mercadopago', $order[0]);
        $this->assertContains('cajupay', $order);
    }

    public function test_pix_restrito_a_cajupay_ignora_preferencia_do_produto(): void
    {
        $seller = $this->seller();
        Setting::set('gateway_order', ['pix' => ['mercadopago', 'cajupay', 'woovi']], null);
        $this->policy()->save($seller->id, ['pix' => ['mode' => 'restricted', 'gateways' => ['cajupay']]], null);
        $this->freshPolicy();

        $order = app(PaymentService::class)->getGatewayOrderForMethod(
            $seller->id,
            'pix',
            null,
            ['pix' => 'woovi', 'pix_redundancy' => ['mercadopago']]
        );

        $this->assertSame(['cajupay'], $order);
    }

    public function test_fallback_so_entre_autorizadas_e_preferencia_vale_entre_elas(): void
    {
        $seller = $this->seller();
        Setting::set('gateway_order', ['pix' => ['mercadopago', 'woovi', 'cajupay']], null);
        $this->policy()->save($seller->id, ['pix' => ['mode' => 'restricted', 'gateways' => ['cajupay', 'woovi']]], null);
        $this->freshPolicy();

        $order = app(PaymentService::class)->getGatewayOrderForMethod($seller->id, 'pix');

        $this->assertSame(['woovi', 'cajupay'], $order);
    }

    public function test_cartao_desligado_some_do_checkout(): void
    {
        $this->connect(['cajupay', 'mercadopago']);
        Setting::set('gateway_order', ['pix' => ['mercadopago'], 'card' => ['mercadopago']], null);
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->policy()->save($seller->id, ['card' => ['mode' => 'disabled']], null);
        $this->freshPolicy();

        $ids = array_column(app(PaymentService::class)->availablePaymentMethodsForCheckout($product), 'id');

        $this->assertContains('pix', $ids);
        $this->assertNotContains('card', $ids);
    }

    public function test_metodo_restrito_mostra_a_adquirente_autorizada_no_checkout(): void
    {
        $this->connect(['cajupay', 'mercadopago']);
        Setting::set('gateway_order', ['pix' => ['mercadopago', 'cajupay']], null);
        $seller = $this->seller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $this->policy()->save($seller->id, ['pix' => ['mode' => 'restricted', 'gateways' => ['cajupay']]], null);
        $this->freshPolicy();

        $pix = collect(app(PaymentService::class)->availablePaymentMethodsForCheckout($product))->firstWhere('id', 'pix');

        $this->assertSame('cajupay', $pix['gateway_slug'] ?? null);
    }

    public function test_desligamento_da_plataforma_vence_a_configuracao_do_produto(): void
    {
        $seller = $this->seller();
        Setting::set('gateway_order', ['boleto' => []], null);

        $order = app(PaymentService::class)->getGatewayOrderForMethod($seller->id, 'boleto', null, ['boleto' => 'efi']);

        $this->assertSame([], $order);
    }

    public function test_carteira_desligada_nao_cai_no_fallback_cajupay_stripe(): void
    {
        $this->connect(['cajupay', 'stripe']);
        $seller = $this->seller();
        $this->policy()->save($seller->id, ['wallet' => ['mode' => 'disabled']], null);
        $this->freshPolicy();

        $this->assertNull(app(PaymentService::class)->getFirstAvailableWalletGateway($seller->id));
        $this->assertFalse($this->policy()->isAllowed($seller->id, 'apple_pay', 'stripe'));
    }

    public function test_is_allowed_para_fluxos_com_adquirente_fixa(): void
    {
        $seller = $this->seller();
        $this->policy()->save($seller->id, [
            'card' => ['mode' => 'restricted', 'gateways' => ['stripe']],
            'open_finance' => ['mode' => 'disabled'],
        ], null);
        $this->freshPolicy();

        $this->assertTrue($this->policy()->isAllowed($seller->id, 'card', 'stripe'));
        $this->assertFalse($this->policy()->isAllowed($seller->id, 'card', 'cajupay'));
        $this->assertFalse($this->policy()->isAllowed($seller->id, 'open_finance', 'linaopenx'));
        $this->assertTrue($this->policy()->isAllowed($seller->id, 'pix', 'woovi'), 'Método sem regra herda.');
    }

    public function test_validacao_rejeita_adquirente_que_nao_atende_o_metodo(): void
    {
        $seller = $this->seller();

        $this->expectException(ValidationException::class);
        $this->policy()->save($seller->id, ['boleto' => ['mode' => 'restricted', 'gateways' => ['woovi']]], null);
    }

    public function test_restrito_sem_adquirente_e_rejeitado(): void
    {
        $seller = $this->seller();

        $this->expectException(ValidationException::class);
        $this->policy()->save($seller->id, ['pix' => ['mode' => 'restricted', 'gateways' => []]], null);
    }

    public function test_alteracao_registra_historico_com_antes_e_depois(): void
    {
        $seller = $this->seller();
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null]);

        $this->policy()->save($seller->id, ['pix' => ['mode' => 'restricted', 'gateways' => ['cajupay']]], $admin->id, 'Conta nova');
        $this->policy()->save($seller->id, ['pix' => ['mode' => 'disabled']], $admin->id, 'Bloqueio');

        $logs = SellerAcquirerPolicyLog::query()->where('tenant_id', $seller->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNull($logs[0]->before);
        $this->assertSame('restricted', $logs[1]->before['pix']['mode']);
        $this->assertSame('disabled', $logs[1]->after['pix']['mode']);
        $this->assertSame($admin->id, $logs[1]->actor_user_id);
        $this->assertSame(1, SellerAcquirerPolicy::query()->where('tenant_id', $seller->id)->count());
    }

    public function test_cartao_salvo_stripe_nao_vai_para_outra_adquirente(): void
    {
        $seller = $this->seller();
        $this->policy()->save($seller->id, ['card' => ['mode' => 'restricted', 'gateways' => ['cajupay']]], null);
        $this->freshPolicy();
        $order = \App\Models\Order::create([
            'tenant_id' => $seller->id,
            'product_id' => $this->createTestProduct(['tenant_id' => $seller->id])->id,
            'status' => 'pending',
            'amount' => 50,
            'email' => 'x@test.com',
            'payment_method' => 'card',
        ]);

        $this->expectException(\RuntimeException::class);
        app(PaymentService::class)->createCardPayment($order, null, ['name' => 'X'], ['payment_token' => 'pm_x'], null, ['stripe']);
    }
}
