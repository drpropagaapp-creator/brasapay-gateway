<?php

namespace Tests\Feature;

use App\Jobs\DeliverApiWebhookJob;
use App\Models\ApiApplication;
use App\Models\ApiApplicationWebhook;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\ApiWebhookDelivery;
use App\Models\Order;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\ApiScopes;
use App\Support\OpenApiSpec;
use App\Services\Api\ApiWebhookDeliveryService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** BrasaPay 4.0 / Onda 3 — Brasa Developers (sandbox, registro de chamadas, webhooks, OpenAPI). */
class BrasaDevelopersTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    /** @return array{app: ApiApplication, app_public: string, app_secret: string} */
    private function app(int $tenantId): array
    {
        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();
        $app = ApiApplication::create([
            'tenant_id' => $tenantId, 'name' => 'Dev App', 'slug' => ApiApplication::generateUniqueSlug($tenantId, 'Dev App'),
            'api_key_hash' => ApiApplication::hashApiKey('legacy-unused'), 'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret), 'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [], 'is_active' => true, 'is_legacy' => true, 'scopes' => ApiScopes::legacyDefaults(),
            'webhook_url' => 'https://8.8.8.8/hook', 'webhook_secret' => 'whsec_teste', 'webhook_events' => null, 'webhook_enabled' => true,
        ]);

        return ['app' => $app, 'app_public' => $public, 'app_secret' => $secret];
    }

    /** @return array{0: string, 1: string} public, secret */
    private function key(ApiApplication $app, string $env): array
    {
        $sandbox = $env === ApiKey::ENV_SANDBOX;
        $secret = ApiKey::generateSecretKey($sandbox);
        $public = ApiKey::generatePublicKey($sandbox);
        ApiKey::create([
            'tenant_id' => $app->tenant_id, 'api_application_id' => $app->id, 'name' => $env, 'public_key' => $public,
            'secret_key_hash' => ApiKey::hashSecretKey($secret), 'scopes' => [ApiScopes::WILDCARD], 'allowed_ips' => [],
            'strict_idempotency' => false, 'async_payments' => false, 'rate_limit_tier' => 'standard', 'is_active' => true, 'environment' => $env,
        ]);

        return [$public, $secret];
    }

    private function sandboxEndpoint(ApiApplication $app): ApiApplicationWebhook
    {
        return ApiApplicationWebhook::create(['api_application_id' => $app->id, 'name' => 'Teste', 'url' => 'https://1.1.1.1/sbx',
            'secret' => 'whsec_sandbox', 'events' => null, 'is_active' => true, 'environment' => ApiKey::ENV_SANDBOX]);
    }

    private function headers(array $pair): array
    {
        return ['X-Public-Key' => $pair[0], 'X-Secret-Key' => $pair[1]];
    }

    public function test_credenciais_separadas_por_ambiente(): void
    {
        $a = $this->app($this->seller()->id);
        $sandbox = $this->key($a['app'], ApiKey::ENV_SANDBOX);
        $live = $this->key($a['app'], ApiKey::ENV_LIVE);

        $this->assertStringStartsWith('gpk_test_', $sandbox[0]);
        $this->withHeaders($this->headers($sandbox))->getJson('/api/v1/balance')->assertStatus(403);
        $this->withHeaders($this->headers($live))->getJson('/api/sandbox/v1/balance')->assertStatus(403);
        $this->withHeaders($this->headers([$a['app_public'], $a['app_secret']]))->getJson('/api/sandbox/v1/balance')->assertStatus(403);
        $this->withHeaders($this->headers($sandbox))->getJson('/api/sandbox/v1/balance')->assertOk()->assertJsonPath('livemode', false);
    }

    public function test_sandbox_simula_ciclo_sem_efeito_financeiro_e_webhooks_marcados(): void
    {
        Queue::fake();
        $a = $this->app($this->seller()->id);
        $endpoint = $this->sandboxEndpoint($a['app']);
        $h = $this->headers($this->key($a['app'], ApiKey::ENV_SANDBOX));
        $ordersBefore = Order::query()->count();

        $pix = $this->withHeaders($h)->postJson('/api/sandbox/v1/payments/pix', ['amount' => 97.5, 'customer' => ['email' => 'c@x.com']])
            ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('livemode', false)->json();
        $this->assertStringStartsWith('SANDBOX-PIX-', $pix['copy_paste']);

        $this->withHeaders($h)->postJson("/api/sandbox/v1/simulate/payments/{$pix['order_id']}/approve")->assertOk()->assertJsonPath('status', 'completed');
        $this->withHeaders($h)->postJson("/api/sandbox/v1/simulate/payments/{$pix['order_id']}/approve")->assertStatus(422);
        $this->withHeaders($h)->getJson('/api/sandbox/v1/balance')->assertJsonPath('available_balance', 97.5);

        $this->withHeaders($h)->postJson('/api/sandbox/v1/payments/card', ['amount' => 10, 'customer' => ['email' => 'c@x.com'], 'card' => ['payment_token' => 'tok_sandbox_declined']])
            ->assertStatus(422)->assertJsonPath('status', 'refused');

        $this->withHeaders($h)->postJson('/api/sandbox/v1/withdrawals', ['amount' => 500, 'pix_key' => 'a@b.com', 'pix_key_type' => 'email'])->assertStatus(422);
        $w = $this->withHeaders($h)->postJson('/api/sandbox/v1/withdrawals', ['amount' => 50, 'pix_key' => 'a@b.com', 'pix_key_type' => 'email'])->assertCreated()->json();
        $this->withHeaders($h)->postJson("/api/sandbox/v1/simulate/withdrawals/{$w['withdrawal_id']}/fail", ['reason' => 'teste'])
            ->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('failed_reason', 'teste');

        $this->assertSame($ordersBefore, Order::query()->count(), 'Sandbox não cria pedido real.');
        $this->assertSame(0, Withdrawal::query()->count(), 'Sandbox não cria saque real.');

        $deliveries = ApiWebhookDelivery::query()->where('api_application_id', $a['app']->id)->get();
        $this->assertEqualsCanonicalizing(['order.pending', 'pix.generated', 'order.completed', 'withdrawal.created', 'withdrawal.failed'], $deliveries->pluck('event')->all());
        $this->assertTrue($deliveries->every(fn ($d) => $d->livemode === false && ($d->payload['livemode'] ?? null) === false));
        // Nunca para a URL de produção (legada) da aplicação: só para o endpoint de sandbox.
        $this->assertSame(['https://1.1.1.1/sbx'], $deliveries->pluck('url')->unique()->values()->all());
        $this->assertSame([$endpoint->id], $deliveries->pluck('api_application_webhook_id')->unique()->values()->all());
        Queue::assertPushed(DeliverApiWebhookJob::class, 5);
    }

    public function test_sandbox_nunca_entrega_em_url_de_producao(): void
    {
        Queue::fake();
        $a = $this->app($this->seller()->id);
        $h = $this->headers($this->key($a['app'], ApiKey::ENV_SANDBOX));
        $this->withHeaders($h)->postJson('/api/sandbox/v1/payments/pix', ['amount' => 5, 'customer' => ['email' => 'c@x.com']])->assertCreated();
        $this->assertSame(0, ApiWebhookDelivery::query()->count(), 'Sem endpoint de sandbox, o evento de teste não sai.');

        $this->sandboxEndpoint($a['app']);
        app(ApiWebhookDeliveryService::class)->dispatch($a['app'], 'order.completed', ['order_id' => 'real']);
        $live = ApiWebhookDelivery::query()->get();
        $this->assertCount(1, $live);
        $this->assertSame('https://8.8.8.8/hook', $live[0]->url, 'Evento real continua na URL de produção.');
        $this->assertTrue($live[0]->livemode);

        // Entrega de sandbox sem o endpoint de sandbox de origem falha em vez de cair na URL de produção.
        $orphan = ApiWebhookDelivery::create(['tenant_id' => $a['app']->tenant_id, 'api_application_id' => $a['app']->id, 'event' => 'order.completed',
            'url' => 'https://8.8.8.8/hook', 'status' => ApiWebhookDelivery::STATUS_PENDING, 'payload' => [], 'livemode' => false]);
        (new DeliverApiWebhookJob((string) $orphan->id))->handle(app(ApiWebhookDeliveryService::class));
        $this->assertSame(ApiWebhookDelivery::STATUS_FAILED, $orphan->fresh()->status);
    }

    public function test_endpoint_de_sandbox_tem_secret_proprio_e_nao_vira_url_de_producao(): void
    {
        $s = $this->seller();
        $a = $this->app($s->id);
        $this->actingAs($s)->post("/aplicacoes-api/{$a['app']->id}/webhooks", ['webhook_url' => 'https://1.1.1.1/sbx', 'environment' => 'sandbox'])->assertRedirect();

        $endpoint = ApiApplicationWebhook::query()->where('api_application_id', $a['app']->id)->firstOrFail();
        $this->assertSame(ApiKey::ENV_SANDBOX, $endpoint->environment);
        $this->assertNotSame('whsec_teste', $endpoint->secret, 'Sandbox não herda o secret de produção.');
        $app = $a['app']->fresh();
        $this->assertSame('https://8.8.8.8/hook', $app->webhook_url, 'URL de produção da aplicação não muda.');
        $this->assertSame('whsec_teste', $app->webhook_secret);

        $this->actingAs($s)->post("/aplicacoes-api/{$a['app']->id}/webhooks", ['webhook_url' => 'https://1.1.1.2/sbx', 'environment' => 'sandbox', 'webhook_secret' => 'whsec_teste']);
        $this->assertSame(1, ApiApplicationWebhook::query()->where('api_application_id', $a['app']->id)->count(), 'Secret de produção recusado no sandbox.');
    }

    public function test_registra_chamadas_sem_corpo(): void
    {
        $a = $this->app($this->seller()->id);
        $h = $this->headers($this->key($a['app'], ApiKey::ENV_SANDBOX));
        $this->withHeaders($h)->postJson('/api/sandbox/v1/payments/pix', ['amount' => 0, 'customer' => ['email' => 'segredo@x.com']])->assertStatus(422);

        $log = ApiRequestLog::query()->where('api_application_id', $a['app']->id)->firstOrFail();
        $this->assertSame(422, $log->status);
        $this->assertSame('sandbox', $log->environment);
        $this->assertSame('api/sandbox/v1/payments/pix', $log->route);
        $this->assertStringNotContainsString('segredo', json_encode($log->toArray()));
    }

    public function test_portal_e_detalhe_da_entrega_mascarado_e_isolado(): void
    {
        Queue::fake();
        $s = $this->seller();
        $a = $this->app($s->id);
        $this->sandboxEndpoint($a['app']);
        $delivery = ApiWebhookDelivery::create([
            'tenant_id' => $s->id, 'api_application_id' => $a['app']->id, 'event' => 'order.completed', 'url' => 'https://8.8.8.8/hook',
            'status' => ApiWebhookDelivery::STATUS_DELIVERED, 'payload' => ['email' => 'maria@x.com', 'customer' => ['cpf' => '12345678901']],
        ]);

        $this->actingAs($s)->get('/desenvolvedores')->assertOk();
        $json = $this->actingAs($s)->getJson("/desenvolvedores/entregas/{$delivery->id}")->assertOk()->json();
        $this->assertSame('m***@x.com', $json['payload']['email']);
        $this->assertStringNotContainsString('12345678901', json_encode($json));

        $this->actingAs($this->seller())->getJson("/desenvolvedores/entregas/{$delivery->id}")->assertNotFound();
        $this->actingAs($s)->postJson("/desenvolvedores/{$a['app']->id}/webhook-teste", ['event' => 'order.completed'])->assertStatus(202);
    }

    public function test_openapi_so_documenta_rotas_existentes(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($r) => collect($r->methods())->map(fn ($m) => strtolower($m).' /'.$r->uri()))->all();
        foreach (OpenApiSpec::operations() as [$method, $path]) {
            $this->assertContains("{$method} /api{$path}", $registered, "Documentado mas inexistente: {$method} {$path}");
        }
        $this->getJson('/docs/api-pagamentos/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0');
    }
}
