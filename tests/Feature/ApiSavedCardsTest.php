<?php

namespace Tests\Feature;

use App\Gateways\Pagarme\PagarmeDriver;
use App\Gateways\Stripe\StripeDriver;
use App\Models\ApiApplication;
use App\Models\ApiCheckoutSession;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\SavedPaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Support\ApiScopes;
use App\Support\ApiWebhookPayloadBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Cartão pela API para o sistema do seller cobrar assinatura no cartão:
 * capacidades → checkout hospedado com save_card → cobrança do cartão salvo → remoção.
 */
class ApiSavedCardsTest extends TestCase
{
    private User $seller;

    private ApiApplication $apiApp;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        config(['getfy.checkout_security.min_seconds_before_pay' => 0]);
        $this->withoutMiddleware(\App\Http\Middleware\EnsureInstalled::class);
        // Em produção (Postgres) pedido de API sem produto é permitido; o SQLite dos testes não herdou isso.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('orders', fn (Blueprint $t) => $t->string('product_id', 36)->nullable()->change());
        }

        $this->seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $this->seller->forceFill([
            'tenant_id' => $this->seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();
        Setting::set('api_pix_enabled', true, null);

        [$this->apiApp, $this->headers] = $this->apiApp();
    }

    /**
     * @return array{0: ApiApplication, 1: array<string, string>}
     */
    private function apiApp(): array
    {
        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();
        $legacy = 'legacy_'.Str::random(12);
        $app = ApiApplication::create([
            'tenant_id' => $this->seller->id,
            'name' => 'Sistema do seller',
            'slug' => ApiApplication::generateUniqueSlug((int) $this->seller->id, 'Sistema '.Str::random(4)),
            'api_key_hash' => ApiApplication::hashApiKey($legacy),
            'legacy_api_key_sha256' => hash('sha256', $legacy),
            'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
            'webhook_url' => null,
            'default_return_url' => null,
            'webhook_secret' => null,
            'checkout_sidebar_bg' => null,
        ]);

        return [$app, ['X-Public-Key' => $public, 'X-Secret-Key' => $secret]];
    }

    private function cardGateway(string $slug, array $credentials): void
    {
        Setting::set('gateway_order', ['pix' => [], 'card' => [$slug], 'boleto' => [], 'pix_auto' => []], null);
        $cred = new GatewayCredential(['tenant_id' => null, 'gateway_slug' => $slug, 'is_connected' => true, 'is_enabled' => true]);
        $cred->setEncryptedCredentials($credentials);
        $cred->save();
    }

    private function stripe(): void
    {
        $this->cardGateway('stripe', ['secret_key' => 'sk_test_x', 'publishable_key' => 'pk_test_x']);
    }

    /** Cartão já guardado e confirmado (primeira cobrança paga). */
    private function savedCard(?ApiApplication $app = null, string $status = 'completed', ?User $buyer = null): SavedPaymentMethod
    {
        $app ??= $this->apiApp;
        $buyer ??= User::factory()->create(['email' => 'cliente@example.com']);
        $order = Order::create([
            'tenant_id' => $this->seller->id,
            'user_id' => $buyer->id,
            'api_application_id' => $app->id,
            'status' => $status,
            'amount' => 97,
            'email' => $buyer->email,
            'payment_method' => 'card',
            'gateway' => 'stripe',
            'metadata' => [],
        ]);

        return SavedPaymentMethod::create([
            'tenant_id' => $this->seller->id,
            'user_id' => $buyer->id,
            'api_application_id' => $app->id,
            'source_order_id' => $order->id,
            'gateway' => 'stripe',
            'gateway_payment_method_id' => 'pm_saved',
            'gateway_customer_id' => 'cus_saved',
            'last_four' => '4242',
            'brand' => 'visa',
            'exp_month' => 12,
            'exp_year' => 2031,
            'type' => 'card',
        ]);
    }

    private function chargeBody(SavedPaymentMethod $card, array $extra = []): array
    {
        return array_merge([
            'saved_card_id' => (string) $card->id,
            'customer' => ['email' => 'cliente@example.com', 'name' => 'Cliente'],
            'amount' => 49.9,
            'metadata' => ['invoice_id' => 'inv_1'],
        ], $extra);
    }

    public function test_capacidades_mostram_a_adquirente_de_cartao_atual(): void
    {
        $this->stripe();

        $resp = $this->withHeaders($this->headers)->getJson('/api/v1/payment-methods');

        $resp->assertOk()
            ->assertJsonPath('methods.card.available', true)
            ->assertJsonPath('methods.card.gateway', 'stripe')
            ->assertJsonPath('methods.card.hosted_checkout', true)
            ->assertJsonPath('methods.card.saved_cards', true);
    }

    public function test_capacidades_com_adquirente_que_nao_guarda_cartao(): void
    {
        $this->cardGateway('pagarme', ['secret_key' => 'sk_test', 'public_key' => 'pk_test']);

        $this->withHeaders($this->headers)->getJson('/api/v1/payment-methods')
            ->assertOk()
            ->assertJsonPath('methods.card.gateway', 'pagarme')
            ->assertJsonPath('methods.card.hosted_checkout', true)
            ->assertJsonPath('methods.card.saved_cards', false);
    }

    public function test_sessao_com_save_card_e_criada_quando_a_adquirente_guarda_cartao(): void
    {
        $this->stripe();

        $resp = $this->withHeaders($this->headers)->postJson('/api/v1/checkout/sessions', [
            'customer' => ['email' => 'cliente@example.com', 'name' => 'Cliente'],
            'amount' => 97,
            'save_card' => true,
            'payment_methods' => ['card'],
        ]);

        $resp->assertCreated()->assertJsonStructure(['session_id', 'checkout_url', 'expires_at']);
        $session = ApiCheckoutSession::findOrFail($resp->json('session_id'));
        $this->assertTrue($session->save_card);
        $this->assertSame(['card'], $session->payment_methods);
        $this->assertTrue($session->allowsMethod('card'));
        $this->assertFalse($session->allowsMethod('pix'));
    }

    public function test_sessao_com_save_card_recusa_adquirente_que_nao_guarda_cartao(): void
    {
        $this->cardGateway('pagarme', ['secret_key' => 'sk_test', 'public_key' => 'pk_test']);

        $this->withHeaders($this->headers)->postJson('/api/v1/checkout/sessions', [
            'customer' => ['email' => 'cliente@example.com'],
            'amount' => 97,
            'save_card' => true,
        ])->assertStatus(422)->assertJsonPath('gateway', 'pagarme');
    }

    public function test_checkout_hospedado_cobra_em_reais_e_guarda_o_cartao(): void
    {
        Event::fake([\App\Events\OrderPending::class]);
        $this->stripe();
        $session = ApiCheckoutSession::create([
            'api_application_id' => $this->apiApp->id,
            'tenant_id' => $this->seller->id,
            'session_token' => (string) Str::uuid(),
            'customer' => ['email' => 'cliente@example.com', 'name' => 'Cliente'],
            'amount' => 97,
            'currency' => 'BRL',
            'metadata' => ['invoice_id' => 'inv_0'],
            'expires_at' => now()->addMinutes(30),
            'save_card' => true,
            'payment_methods' => ['card'],
        ]);

        $captured = null;
        $driver = Mockery::mock(StripeDriver::class);
        $driver->shouldReceive('createCardPayment')->once()
            ->andReturnUsing(function ($credentials, $amount, $consumer, $externalId, array $card) use (&$captured) {
                $captured = $card;

                return [
                    'transaction_id' => 'pi_first',
                    'status' => 'paid',
                    'saved_card' => ['payment_method_id' => 'pm_new', 'customer_id' => 'cus_new', 'brand' => 'visa', 'last_four' => '4242', 'exp_month' => 1, 'exp_year' => 2030],
                ];
            });
        $this->app->instance(StripeDriver::class, $driver);

        $this->post('/api-checkout/pay', [
            'session_token' => $session->session_token,
            'payment_method' => 'card',
            'payment_token' => 'pm_new',
            'card_mask' => '**** 4242',
        ])->assertRedirect();

        $this->assertSame('brl', $captured['currency']);
        $this->assertTrue($captured['save']);

        $order = Order::query()->where('api_checkout_session_id', $session->id)->firstOrFail();
        $this->assertSame('completed', $order->status);
        $saved = SavedPaymentMethod::query()->where('source_order_id', $order->id)->firstOrFail();
        $this->assertSame((int) $this->apiApp->id, (int) $saved->api_application_id);
        $this->assertSame('cus_new', $saved->gateway_customer_id);
        $this->assertSame('pm_new', $saved->gateway_payment_method_id);

        // O aviso de pago já leva o cartão salvo para o sistema do seller guardar.
        $payload = ApiWebhookPayloadBuilder::orderPayload($order->fresh(), 'order.completed');
        $this->assertSame((string) $saved->id, $payload['saved_card']['id']);
        $this->assertSame('active', $payload['saved_card']['status']);
        $this->assertSame('4242', $payload['saved_card']['last_four']);
    }

    public function test_checkout_hospedado_sem_cartao_pedido_nao_mostra_cartao_automatico(): void
    {
        $this->stripe();
        $session = ApiCheckoutSession::create([
            'api_application_id' => $this->apiApp->id,
            'tenant_id' => $this->seller->id,
            'session_token' => (string) Str::uuid(),
            'customer' => ['email' => 'cliente@example.com'],
            'amount' => 97,
            'currency' => 'BRL',
            'expires_at' => now()->addMinutes(30),
        ]);

        // Aplicação sem cartão fixado e sessão sem payment_methods: comportamento antigo (sem cartão).
        $this->assertNull(app(\App\Services\Api\ApiCardService::class)->hostedCardGateway($this->apiApp, $session, null));
    }

    public function test_cobra_cartao_salvo_sem_o_cliente_presente(): void
    {
        $this->stripe();
        $card = $this->savedCard();

        $captured = null;
        $driver = Mockery::mock(StripeDriver::class);
        $driver->shouldReceive('createCardPayment')->once()
            ->andReturnUsing(function ($credentials, $amount, $consumer, $externalId, array $c) use (&$captured) {
                $captured = ['amount' => $amount, 'card' => $c];

                return ['transaction_id' => 'pi_next', 'status' => 'paid'];
            });
        $this->app->instance(StripeDriver::class, $driver);

        $resp = $this->withHeaders($this->headers)->postJson('/api/v1/payments/card/saved', $this->chargeBody($card));

        $resp->assertCreated()->assertJsonPath('status', 'completed')->assertJsonPath('transaction_id', 'pi_next');
        $this->assertSame(49.9, (float) $captured['amount']);
        $this->assertSame('pm_saved', $captured['card']['payment_token']);
        $this->assertSame('cus_saved', $captured['card']['customer_id']);
        $this->assertTrue($captured['card']['off_session']);
        $this->assertSame('brl', $captured['card']['currency']);

        $order = Order::findOrFail($resp->json('order_id'));
        $this->assertSame('completed', $order->status);
        $this->assertSame((string) $card->id, $order->metadata['saved_card_id']);
        $this->assertSame('inv_1', $order->metadata['invoice_id']);
    }

    public function test_cartao_pendente_nao_e_cobrado(): void
    {
        $this->stripe();
        $card = $this->savedCard(status: 'pending');

        $this->withHeaders($this->headers)->postJson('/api/v1/payments/card/saved', $this->chargeBody($card))
            ->assertStatus(422);
        $this->assertSame(1, Order::count());
    }

    public function test_cartao_de_outra_aplicacao_nao_existe_para_esta(): void
    {
        $this->stripe();
        [$other] = $this->apiApp();
        $card = $this->savedCard($other);

        $this->withHeaders($this->headers)->postJson('/api/v1/payments/card/saved', $this->chargeBody($card))
            ->assertNotFound();
        $this->withHeaders($this->headers)->getJson('/api/v1/saved-cards/'.$card->id)->assertNotFound();
    }

    public function test_cartao_de_outro_cliente_e_recusado(): void
    {
        $this->stripe();
        $card = $this->savedCard();

        $this->withHeaders($this->headers)
            ->postJson('/api/v1/payments/card/saved', $this->chargeBody($card, ['customer' => ['email' => 'outra@example.com']]))
            ->assertStatus(422);
    }

    public function test_banco_pede_confirmacao_devolve_codigo_e_nao_deixa_pedido(): void
    {
        $this->stripe();
        $card = $this->savedCard();
        $driver = Mockery::mock(StripeDriver::class);
        $driver->shouldReceive('createCardPayment')->once()->andThrow(new \RuntimeException(StripeDriver::OFF_SESSION_AUTH_MESSAGE));
        $this->app->instance(StripeDriver::class, $driver);

        $this->withHeaders($this->headers)->postJson('/api/v1/payments/card/saved', $this->chargeBody($card))
            ->assertStatus(422)
            ->assertJsonPath('code', 'authentication_required');
        $this->assertSame(1, Order::count());
    }

    public function test_cartao_salvo_nunca_tenta_outra_adquirente(): void
    {
        $this->stripe();
        $card = $this->savedCard();
        // Pagar.me primeiro na ordem da conta: mesmo assim só a Stripe (dona do cartão) é chamada.
        Setting::set('gateway_order', ['pix' => [], 'card' => ['pagarme', 'stripe'], 'boleto' => [], 'pix_auto' => []], null);
        $pg = new GatewayCredential(['tenant_id' => null, 'gateway_slug' => 'pagarme', 'is_connected' => true, 'is_enabled' => true]);
        $pg->setEncryptedCredentials(['secret_key' => 'sk', 'public_key' => 'pk']);
        $pg->save();

        $pagarme = Mockery::mock(PagarmeDriver::class);
        $pagarme->shouldNotReceive('createCardPayment');
        $this->app->instance(PagarmeDriver::class, $pagarme);
        $stripe = Mockery::mock(StripeDriver::class);
        $stripe->shouldReceive('createCardPayment')->once()->andReturn(['transaction_id' => 'pi_x', 'status' => 'paid']);
        $this->app->instance(StripeDriver::class, $stripe);

        $this->withHeaders($this->headers)->postJson('/api/v1/payments/card/saved', $this->chargeBody($card))->assertCreated();
    }

    public function test_consulta_e_remove_o_cartao(): void
    {
        $this->stripe();
        $card = $this->savedCard();

        $this->withHeaders($this->headers)->getJson('/api/v1/saved-cards/'.$card->id)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('brand', 'visa')
            ->assertJsonPath('last_four', '4242');

        $driver = Mockery::mock(StripeDriver::class);
        $driver->shouldReceive('forgetSavedCard')->once()->with(Mockery::type('array'), 'pm_saved');
        $this->app->instance(StripeDriver::class, $driver);

        $this->withHeaders($this->headers)->deleteJson('/api/v1/saved-cards/'.$card->id)
            ->assertOk()->assertJsonPath('deleted', true);
        $this->assertNull(SavedPaymentMethod::find($card->id));
    }
}
