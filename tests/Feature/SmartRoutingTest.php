<?php

namespace Tests\Feature;

use App\Exceptions\AmbiguousCardChargeException;
use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Gateways\Pagarme\PagarmeDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\SellerAcquirerPolicy;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\AcquirerAttemptRecorder;
use App\Services\Payments\AcquirerHealthService;
use App\Services\Payments\AcquirerPolicyService;
use App\Services\Payments\SmartRoutingService;
use App\Services\PaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * BrasaPay 4.0 / Onda 1 — monitoramento de adquirentes e Smart Routing.
 */
class SmartRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AcquirerAttemptRecorder::resetCache();
        Cache::flush();
    }

    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    private function connect(string $slug, array $creds): void
    {
        $c = new GatewayCredential(['tenant_id' => null, 'gateway_slug' => $slug, 'is_connected' => true, 'is_enabled' => true]);
        $c->setEncryptedCredentials($creds);
        $c->save();
    }

    private function attempts(string $slug, string $method, array $outcomes, int $latency = 300): void
    {
        foreach ($outcomes as $outcome => $n) {
            for ($i = 0; $i < $n; $i++) {
                DB::table('acquirer_attempts')->insert([
                    'gateway_slug' => $slug, 'method' => $method, 'outcome' => $outcome,
                    'latency_ms' => $latency, 'position' => 1, 'created_at' => now()->subMinutes(5),
                ]);
            }
        }
    }

    private function enable(string $strategy = 'performance', array $methods = ['pix', 'card', 'boleto']): void
    {
        Setting::set('smart_routing', json_encode([
            'enabled' => true, 'strategy' => $strategy, 'methods' => $methods, 'min_sample' => 10, 'window_hours' => 24,
            'weights' => ['approval' => 0.35, 'stability' => 0.3, 'latency' => 0.15, 'cost' => 0.2],
        ]), null);
    }

    public function test_desligado_por_padrao_mantem_a_ordem(): void
    {
        $this->attempts('pagarme', 'card', ['technical_error' => 50]);
        $this->attempts('mercadopago', 'card', ['success' => 50]);

        [$order, $strategy] = app(SmartRoutingService::class)->reorder(['pagarme', 'mercadopago'], 'card', null);

        $this->assertSame(['pagarme', 'mercadopago'], $order);
        $this->assertSame('manual', $strategy);
    }

    public function test_performance_promove_a_adquirente_estavel_e_amostra_pequena_nao_se_move(): void
    {
        $this->enable();
        $this->attempts('pagarme', 'card', ['success' => 20, 'technical_error' => 20]);
        $this->attempts('mercadopago', 'card', ['success' => 40]);
        $this->attempts('stripe', 'card', ['success' => 3]); // amostra pequena

        [$order] = app(SmartRoutingService::class)->reorder(['pagarme', 'stripe', 'mercadopago'], 'card', null);

        $this->assertSame(['mercadopago', 'stripe', 'pagarme'], $order);
    }

    public function test_roteamento_nunca_usa_adquirente_proibida_pela_politica_do_seller(): void
    {
        $this->enable();
        $seller = $this->seller();
        Setting::set('gateway_order', ['card' => ['pagarme', 'mercadopago']], null);
        $this->connect('pagarme', ['secret_key' => 'sk_test', 'public_key' => 'pk_test']);
        $this->connect('mercadopago', ['public_key' => 'pk', 'access_token' => 'TEST']);
        // Mercado Pago é muito melhor, mas o seller só pode usar Pagar.me.
        $this->attempts('pagarme', 'card', ['success' => 10, 'technical_error' => 30]);
        $this->attempts('mercadopago', 'card', ['success' => 40]);
        app(AcquirerPolicyService::class)->save($seller->id, ['card' => ['mode' => SellerAcquirerPolicy::MODE_RESTRICTED, 'gateways' => ['pagarme']]], null, 'teste');
        $this->app->forgetScopedInstances();

        $order = app(PaymentService::class)->getGatewayOrderForMethod($seller->id, 'card');

        $this->assertSame(['pagarme'], $order);
    }

    public function test_seller_desligado_nao_e_reordenado(): void
    {
        $this->enable();
        $seller = $this->seller();
        Setting::set('smart_routing_tenant', 'off', $seller->id);
        $this->attempts('pagarme', 'card', ['technical_error' => 40]);
        $this->attempts('mercadopago', 'card', ['success' => 40]);

        [$order] = app(SmartRoutingService::class)->reorder(['pagarme', 'mercadopago'], 'card', $seller->id);

        $this->assertSame(['pagarme', 'mercadopago'], $order);
    }

    public function test_timeout_no_cartao_nao_tenta_outra_adquirente_e_registra_tentativa(): void
    {
        $seller = $this->seller();
        Setting::set('gateway_order', ['card' => ['pagarme', 'mercadopago']], null);
        $this->connect('pagarme', ['secret_key' => 'sk_test', 'public_key' => 'pk_test']);
        $this->connect('mercadopago', ['public_key' => 'pk', 'access_token' => 'TEST']);

        $pagarme = Mockery::mock(PagarmeDriver::class);
        $pagarme->shouldReceive('createCardPayment')->once()->andThrow(new ConnectionException('cURL error 28: Operation timed out'));
        $this->app->instance(PagarmeDriver::class, $pagarme);
        $mp = Mockery::mock(MercadoPagoDriver::class);
        $mp->shouldNotReceive('createCardPayment');
        $this->app->instance(MercadoPagoDriver::class, $mp);

        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $order = Order::create([
            'tenant_id' => $seller->id, 'user_id' => $seller->id, 'product_id' => $product->id,
            'status' => 'pending', 'amount' => 100, 'email' => 'c@x.com', 'payment_method' => 'card', 'metadata' => [],
        ]);

        try {
            app(PaymentService::class)->createCardPayment($order, $product, ['name' => 'A', 'document' => '1', 'email' => 'c@x.com'], ['payment_token' => 'tok']);
            $this->fail('Deveria interromper o fallback no timeout.');
        } catch (AmbiguousCardChargeException) {
        }

        $this->assertSame('pagarme', $order->fresh()->metadata['card_ambiguous_attempt']['gateway'] ?? null);
        $this->assertSame(1, DB::table('acquirer_attempts')->where('gateway_slug', 'pagarme')->where('outcome', 'timeout')->count());
    }

    public function test_metricas_separam_recusa_de_erro_tecnico(): void
    {
        $this->attempts('pagarme', 'card', ['success' => 30, 'declined' => 10, 'technical_error' => 10]);

        $m = app(AcquirerHealthService::class)->stats(24, 10)['card']['pagarme'];

        $this->assertSame(50, $m['attempts']);
        $this->assertSame(0.75, $m['success_rate']); // 30 / (30 + 10 técnicos); recusa não entra
        $this->assertSame(0.2, $m['decline_rate']);
        $this->assertTrue($m['sufficient_sample']);
    }
}
