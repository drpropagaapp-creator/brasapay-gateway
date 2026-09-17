<?php

namespace Tests\Unit\Versell;

use App\Events\SubscriptionCancelled;
use App\Gateways\Versell\VersellDriver;
use App\Http\Controllers\Webhooks\VersellWebhookController;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Versell\VersellPixAutoLifecycleService;
use App\Services\Versell\VersellPixAutoRenewalService;
use App\Services\Versell\VersellPixRecorrenteService;
use App\Support\GatewayWebhookUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class VersellPixAutoTest extends TestCase
{
    private string $tmpDir;

    private string $cashInCert;

    private string $cashInKey;

    private string $cashOutCert;

    private string $cashOutKey;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'versell_pa_'.uniqid('', true);
        mkdir($this->tmpDir, 0700, true);

        $this->cashInCert = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_in.crt';
        $this->cashInKey = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_in.key';
        $this->cashOutCert = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_out.crt';
        $this->cashOutKey = $this->tmpDir.DIRECTORY_SEPARATOR.'cash_out.key';

        file_put_contents($this->cashInCert, "-----BEGIN CERTIFICATE-----\nIN\n-----END CERTIFICATE-----\n");
        file_put_contents($this->cashInKey, "-----BEGIN PRIVATE KEY-----\nIN\n-----END PRIVATE KEY-----\n");
        file_put_contents($this->cashOutCert, "-----BEGIN CERTIFICATE-----\nOUT\n-----END CERTIFICATE-----\n");
        file_put_contents($this->cashOutKey, "-----BEGIN PRIVATE KEY-----\nOUT\n-----END PRIVATE KEY-----\n");

        Cache::flush();
        config(['getfy.api.inbound_webhooks_async' => false]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->cashInCert, $this->cashInKey, $this->cashOutCert, $this->cashOutKey] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleCredentials(): array
    {
        return [
            'cash_in' => [
                'client_id' => 'ci_client',
                'client_secret' => 'ci_secret',
                'certificate_path' => $this->cashInCert,
                'private_key_path' => $this->cashInKey,
                'pix_key' => 'pix@versell.test',
            ],
            'cash_out' => [
                'client_id' => 'co_client',
                'client_secret' => 'co_secret',
                'certificate_path' => $this->cashOutCert,
                'private_key_path' => $this->cashOutKey,
            ],
        ];
    }

    public function test_config_includes_pix_auto(): void
    {
        $methods = config('gateways.gateways.versell.methods', []);
        $this->assertContains('pix_auto', $methods);
        $order = config('gateways.default_order.pix_auto', []);
        $this->assertContains('versell', $order);
    }

    public function test_webhook_urls_pix_auto(): void
    {
        config([
            'app.url' => 'https://pay.exemplo.com',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/versell/pix-automatico',
            GatewayWebhookUrl::forGateway('versell.pix_auto')
        );
        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/versell/pix-automatico/rec',
            GatewayWebhookUrl::forGateway('versell.pix_auto.rec')
        );
    }

    public function test_jornada3_flow_creates_loc_cob_rec(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/locrec' => Http::response([
                'id' => 42,
                'location' => 'pix.example/loc/42',
                'criacao' => now()->toIso8601String(),
            ], 201),
            'api.pix.basspago.com.br/cob/*' => Http::response([
                'txid' => 'pixauto1abcdefghijklmnopqrstuv',
                'status' => 'ATIVA',
                'pixCopiaECola' => '00020126cobimediata',
                'loc' => ['id' => 99],
            ], 201),
            'api.pix.basspago.com.br/rec' => Http::response([
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'CRIADA',
            ], 201),
            'api.pix.basspago.com.br/rec/*' => Http::response([
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'CRIADA',
                'dadosQR' => [
                    'pixCopiaECola' => '00020126qrccomposito',
                ],
            ], 200),
        ]);

        $service = new VersellPixRecorrenteService($this->sampleCredentials());
        $loc = $service->createLocRec();
        $this->assertSame(42, (int) $loc['id']);

        $txid = 'pixauto1abcdefghijklmnopqrstuv';
        $cob = $service->createCobWithTxid(
            $txid,
            35.0,
            ['name' => 'Fulano', 'document' => '52998224725', 'email' => 'a@b.com'],
            'pix@versell.test'
        );
        $this->assertSame($txid, $cob['txid']);
        $this->assertSame('00020126cobimediata', $cob['copy_paste']);

        $rec = $service->createRecurrence(
            42,
            $txid,
            ['name' => 'Fulano', 'document' => '52998224725', 'email' => 'a@b.com'],
            35.0,
            now()->addMonth()->format('Y-m-d'),
            now()->addYears(10)->format('Y-m-d'),
            '00000001',
            'Assinatura'
        );
        $this->assertSame('RN1234567820260801abcdefghijk', $rec['idRec']);

        $recData = $service->getRecurrence($rec['idRec'], $txid);
        $this->assertSame('00020126qrccomposito', $recData['dadosQR']['pixCopiaECola'] ?? null);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?? '', '/locrec'));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/cob/'));
        Http::assertSent(function ($r) {
            return $r->method() === 'POST'
                && str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?? '', '/rec')
                && ($r['ativacao']['dadosJornada']['txid'] ?? null) !== null
                && ($r['calendario']['periodicidade'] ?? null) === 'MENSAL'
                && ($r['politicaRetentativa'] ?? null) === 'PERMITE_3R_7D';
        });
    }

    public function test_cobr_webhook_dispatches_paid(): void
    {
        if (! Schema::hasTable('orders')) {
            $this->markTestSkipped('orders table');
        }

        Bus::fake([ProcessPaymentWebhook::class]);

        $seller = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'gateway' => 'versell',
            'gateway_id' => 'cobrtxid1234567890abcdefghij',
            'payment_method' => 'pix_auto',
            'amount' => 35,
            'email' => 'a@b.com',
            'metadata' => ['versell_pix_auto_id_rec' => 'RN1234567820260801abcdefghijk'],
        ]);

        $request = Request::create('/webhooks/gateways/versell/pix-automatico/cobr', 'POST', [
            'cobsr' => [[
                'idRec' => 'RN1234567820260801abcdefghijk',
                'txid' => 'cobrtxid1234567890abcdefghij',
                'status' => 'ATIVA',
                'tentativas' => [[
                    'status' => 'PAGA',
                    'endToEndId' => 'E123ENDTOEND',
                    'tipo' => 'AGND',
                ]],
            ]],
        ]);

        $response = app(VersellWebhookController::class)->pixAutoCobr($request);
        $this->assertSame(200, $response->getStatusCode());
        Bus::assertDispatched(ProcessPaymentWebhook::class);
    }

    public function test_create_cobranca_recorrente(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/cobr/*' => Http::response([
                'txid' => 'nextcobr1234567890abcdefghijkl',
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'CRIADA',
            ], 201),
        ]);

        $service = new VersellPixRecorrenteService($this->sampleCredentials());
        $data = $service->createCobrancaRecorrente(
            'RN1234567820260801abcdefghijk',
            35.0,
            now()->addMonth()->format('Y-m-d'),
            'nextcobr1234567890abcdefghijkl',
            ['name' => 'Fulano', 'email' => 'a@b.com', 'document' => '52998224725'],
            'Renovação'
        );

        $this->assertSame('RN1234567820260801abcdefghijk', $data['idRec']);
        Http::assertSent(function ($r) {
            return $r->method() === 'PUT'
                && str_contains($r->url(), '/cobr/')
                && ($r['devedor']['cpf'] ?? null) === '52998224725';
        });
    }

    public function test_periodicidade_from_interval(): void
    {
        $this->assertSame('SEMANAL', VersellPixRecorrenteService::periodicidadeFromInterval(SubscriptionPlan::INTERVAL_WEEKLY));
        $this->assertSame('TRIMESTRAL', VersellPixRecorrenteService::periodicidadeFromInterval(SubscriptionPlan::INTERVAL_QUARTERLY));
        $this->assertSame('ANUAL', VersellPixRecorrenteService::periodicidadeFromInterval(SubscriptionPlan::INTERVAL_ANNUAL));
        $this->assertSame('MENSAL', VersellPixRecorrenteService::periodicidadeFromInterval(null));
    }

    public function test_ensure_next_cobr_creates_pending_order_when_rec_approved(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }

        $this->seedVersellCredential();
        $ctx = $this->makePixAutoSubscriptionContext();

        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/rec/*' => Http::response([
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'APROVADA',
            ], 200),
            'api.pix.basspago.com.br/cobr/*' => Http::response([
                'txid' => 'pixautorenovtxid1234567890abcd',
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'CRIADA',
            ], 201),
        ]);

        $renewal = app(VersellPixAutoRenewalService::class)->ensureNextCobr($ctx['subscription'], $ctx['order']);
        $this->assertNotNull($renewal);
        $this->assertTrue((bool) $renewal->is_renewal);
        $this->assertSame('pending', $renewal->status);
        $this->assertSame('versell', $renewal->gateway);
        $this->assertNotEmpty($renewal->gateway_id);
        $this->assertSame('RN1234567820260801abcdefghijk', $renewal->metadata['versell_pix_auto_id_rec'] ?? null);

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/cobr/'));
    }

    public function test_ensure_next_cobr_skips_when_rec_not_approved(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }

        $this->seedVersellCredential();
        $ctx = $this->makePixAutoSubscriptionContext();

        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/rec/*' => Http::response([
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'CRIADA',
            ], 200),
        ]);

        $renewal = app(VersellPixAutoRenewalService::class)->ensureNextCobr($ctx['subscription'], $ctx['order']);
        $this->assertNull($renewal);
        $this->assertSame(1, Order::query()->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/cobr/'));
    }

    public function test_rec_aprovada_webhook_schedules_cobr(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }

        $this->seedVersellCredential();
        $ctx = $this->makePixAutoSubscriptionContext();
        $ctx['order']->update(['status' => 'completed']);

        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/rec/*' => Http::response([
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'APROVADA',
            ], 200),
            'api.pix.basspago.com.br/cobr/*' => Http::response([
                'txid' => 'fromwebhookcobr1234567890abcdef',
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'CRIADA',
            ], 201),
        ]);

        $request = Request::create('/webhooks/gateways/versell/pix-automatico/rec', 'POST', [
            'recs' => [[
                'idRec' => 'RN1234567820260801abcdefghijk',
                'status' => 'APROVADA',
            ]],
        ]);

        $response = app(VersellWebhookController::class)->pixAutoRec($request);
        $this->assertSame(200, $response->getStatusCode());

        $renewal = Order::query()->where('is_renewal', true)->first();
        $this->assertNotNull($renewal);
        $this->assertNotEmpty($renewal->gateway_id);
    }

    public function test_cobr_webhook_fallback_creates_order_by_id_rec(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }

        Bus::fake([ProcessPaymentWebhook::class]);
        $this->makePixAutoSubscriptionContext();

        $request = Request::create('/webhooks/gateways/versell/pix-automatico/cobr', 'POST', [
            'cobsr' => [[
                'idRec' => 'RN1234567820260801abcdefghijk',
                'txid' => 'orphanCobrTxid1234567890abcdefg',
                'status' => 'CONCLUIDA',
                'tentativas' => [[
                    'status' => 'PAGA',
                    'endToEndId' => 'E999ENDTOEND',
                ]],
            ]],
        ]);

        $response = app(VersellWebhookController::class)->pixAutoCobr($request);
        $this->assertSame(200, $response->getStatusCode());

        $renewal = Order::query()
            ->where('gateway_id', 'orphanCobrTxid1234567890abcdefg')
            ->first();
        $this->assertNotNull($renewal);
        $this->assertTrue((bool) $renewal->is_renewal);
        Bus::assertDispatched(ProcessPaymentWebhook::class);
    }

    public function test_driver_status_falls_back_to_cobr(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/cob/*' => Http::response(['type' => 'not-found'], 404),
            'api.pix.basspago.com.br/cobr/*' => Http::response([
                'txid' => 'cobrtxid1234567890abcdefghij',
                'status' => 'ATIVA',
                'tentativas' => [['status' => 'PAGA']],
            ], 200),
        ]);

        $status = (new VersellDriver())->getTransactionStatus(
            'cobrtxid1234567890abcdefghij',
            $this->sampleCredentials()
        );
        $this->assertSame('paid', $status);
    }

    public function test_cancel_recurrence_and_retentativa_endpoints(): void
    {
        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response([
                'access_token' => 'token-in',
                'expires_in' => 300,
            ], 200),
            'api.pix.basspago.com.br/rec/*' => Http::response(['idRec' => 'RR1234567820260801abcdefghijk', 'status' => 'CANCELADA'], 200),
            'api.pix.basspago.com.br/cobr/*' => Http::response(['txid' => 'cobrtxid1234567890abcdefghij', 'status' => 'ATIVA'], 200),
        ]);

        $service = new VersellPixRecorrenteService($this->sampleCredentials());
        $service->cancelRecurrence('RR1234567820260801abcdefghijk');
        $service->cancelCobranca('cobrtxid1234567890abcdefghij');
        $service->requestRetentativa('cobrtxid1234567890abcdefghij', '2026-09-13');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/rec/') && ($r['status'] ?? null) === 'CANCELADA');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/cobr/') && ($r['status'] ?? null) === 'CANCELADA');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/retentativa/2026-09-13'));
    }

    public function test_cancel_remote_patches_rec_and_pending_cobr(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }

        $this->seedVersellCredential();
        $ctx = $this->makePixAutoSubscriptionContext();
        $pending = Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $ctx['subscription']->user_id,
            'product_id' => $ctx['subscription']->product_id,
            'subscription_plan_id' => $ctx['subscription']->subscription_plan_id,
            'status' => 'pending',
            'gateway' => 'versell',
            'gateway_id' => 'renewCobrTxid1234567890abcdefg',
            'payment_method' => 'pix_auto',
            'amount' => 35,
            'email' => 'a@b.com',
            'is_renewal' => true,
            'period_start' => now()->addMonth()->startOfDay(),
            'period_end' => now()->addMonths(2)->startOfDay(),
            'metadata' => ['versell_pix_auto_id_rec' => 'RN1234567820260801abcdefghijk'],
        ]);

        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response(['access_token' => 'token-in', 'expires_in' => 300], 200),
            'api.pix.basspago.com.br/rec/*' => Http::response(['status' => 'CANCELADA'], 200),
            'api.pix.basspago.com.br/cobr/*' => Http::response(['status' => 'CANCELADA'], 200),
        ]);

        app(VersellPixAutoLifecycleService::class)->cancelRemote($ctx['subscription']);

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/rec/'));
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/cobr/'));
        $this->assertSame('cancelled', $pending->fresh()->status);
    }

    public function test_subscription_cancelled_event_cancels_versell_rec(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }

        $this->seedVersellCredential();
        $ctx = $this->makePixAutoSubscriptionContext();

        Http::fake([
            'api.pix.basspago.com.br/oauth/token' => Http::response(['access_token' => 'token-in', 'expires_in' => 300], 200),
            'api.pix.basspago.com.br/rec/*' => Http::response(['status' => 'CANCELADA'], 200),
        ]);

        Event::dispatch(new SubscriptionCancelled($ctx['subscription']));
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/rec/RN1234567820260801abcdefghijk'));
    }

    public function test_retry_expired_cobr(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }

        $this->seedVersellCredential();
        $ctx = $this->makePixAutoSubscriptionContext();
        $pending = Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $ctx['subscription']->user_id,
            'product_id' => $ctx['subscription']->product_id,
            'subscription_plan_id' => $ctx['subscription']->subscription_plan_id,
            'status' => 'pending',
            'gateway' => 'versell',
            'gateway_id' => 'expiredCobrTxid1234567890abcde',
            'payment_method' => 'pix_auto',
            'amount' => 35,
            'email' => 'a@b.com',
            'is_renewal' => true,
            'metadata' => ['versell_pix_auto_id_rec' => 'RN1234567820260801abcdefghijk'],
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/oauth/token')) {
                return Http::response(['access_token' => 'token-in', 'expires_in' => 300], 200);
            }
            if (str_contains($request->url(), '/retentativa/')) {
                return Http::response(['txid' => 'expiredCobrTxid1234567890abcde', 'status' => 'ATIVA'], 201);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/cobr/')) {
                return Http::response(['txid' => 'expiredCobrTxid1234567890abcde', 'status' => 'EXPIRADA'], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $ok = app(VersellPixAutoLifecycleService::class)->retryExpiredCobr($pending);
        $this->assertTrue($ok);
        $this->assertSame(1, (int) ($pending->fresh()->metadata['versell_pix_auto_retry_count'] ?? 0));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/retentativa/'));
    }

    public function test_cobr_expired_webhook_requests_retry(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            $this->markTestSkipped('tables');
        }

        $this->seedVersellCredential();
        $ctx = $this->makePixAutoSubscriptionContext();
        Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $ctx['subscription']->user_id,
            'product_id' => $ctx['subscription']->product_id,
            'subscription_plan_id' => $ctx['subscription']->subscription_plan_id,
            'status' => 'pending',
            'gateway' => 'versell',
            'gateway_id' => 'hookExpiredCobr1234567890abcde',
            'payment_method' => 'pix_auto',
            'amount' => 35,
            'email' => 'a@b.com',
            'is_renewal' => true,
            'metadata' => ['versell_pix_auto_id_rec' => 'RN1234567820260801abcdefghijk'],
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/oauth/token')) {
                return Http::response(['access_token' => 'token-in', 'expires_in' => 300], 200);
            }
            if (str_contains($request->url(), '/retentativa/')) {
                return Http::response(['status' => 'ATIVA'], 201);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/cobr/')) {
                return Http::response(['status' => 'EXPIRADA'], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $request = Request::create('/webhooks/gateways/versell/pix-automatico/cobr', 'POST', [
            'cobsr' => [[
                'idRec' => 'RN1234567820260801abcdefghijk',
                'txid' => 'hookExpiredCobr1234567890abcde',
                'status' => 'EXPIRADA',
            ]],
        ]);

        $response = app(VersellWebhookController::class)->pixAutoCobr($request);
        $this->assertSame(200, $response->getStatusCode());
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/retentativa/'));
    }

    /**
     * @return array{order: Order, subscription: Subscription}
     */
    private function makePixAutoSubscriptionContext(): array
    {
        $seller = User::factory()->create(['tenant_id' => 1]);
        $buyer = User::factory()->create(['tenant_id' => 1, 'name' => 'Fulano']);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
        ]);
        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 35,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'p-'.Str::lower(Str::random(8)),
            'position' => 1,
        ]);
        $order = Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'completed',
            'gateway' => 'versell',
            'gateway_id' => 'pixauto1abcdefghijklmnopqrstuv',
            'payment_method' => 'pix_auto',
            'amount' => 35,
            'email' => $buyer->email,
            'cpf' => '52998224725',
            'period_start' => now()->startOfDay(),
            'period_end' => now()->addMonth()->startOfDay(),
            'is_renewal' => false,
            'metadata' => ['versell_pix_auto_id_rec' => 'RN1234567820260801abcdefghijk'],
        ]);
        $subscription = Subscription::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->startOfDay(),
            'current_period_end' => now()->addMonth()->startOfDay(),
            'gateway_subscription_id' => 'RN1234567820260801abcdefghijk',
        ]);

        return ['order' => $order, 'subscription' => $subscription, 'seller' => $seller];
    }

    private function seedVersellCredential(): void
    {
        $cred = new GatewayCredential();
        $cred->tenant_id = null;
        $cred->gateway_slug = 'versell';
        $cred->is_connected = true;
        $cred->is_enabled = true;
        $cred->setEncryptedCredentials($this->sampleCredentials());
        $cred->save();
    }
}
