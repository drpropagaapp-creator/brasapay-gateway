<?php

namespace Tests\Feature;

use App\Gateways\AssetPay\AssetPayDriver;
use App\Models\GatewayCredential;
use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\AssetPay\AssetPayPayoutService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssetPayWebhookTest extends TestCase
{
    private const API = 'https://api.assetpay.cash';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureInstalled::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
        config([
            'queue.default' => 'sync',
            'getfy.api.inbound_webhooks_async' => false,
        ]);
        Event::fake();
    }

    public function test_recebimento_completes_order_after_api_reconfirm(): void
    {
        $this->fakeApi(['/v1/cob/CH1' => ['id' => 'CH1', 'status' => 'CONCLUIDA']]);
        $this->connectAssetPay();
        $order = $this->createAssetPayOrder('CH1');

        $this->postJson('/webhooks/gateways/assetpay', $this->charge('CH1', 'CONCLUIDA', $order->id), [
            'X-Oceanfy-Event' => 'recebimento',
        ])->assertOk()->assertJson(['received' => true]);

        $this->assertSame('completed', $order->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/oauth/token'
            && $r['client_id'] === 'pk_live_in' && $r['grant_type'] === 'client_credentials');
    }

    public function test_paid_body_is_not_trusted_when_api_says_still_active(): void
    {
        $this->fakeApi(['/v1/cob/CH2' => ['id' => 'CH2', 'status' => 'ATIVA']]);
        $this->connectAssetPay();
        $order = $this->createAssetPayOrder('CH2');

        $this->postJson('/webhooks/gateways/assetpay', $this->charge('CH2', 'CONCLUIDA', $order->id), [
            'X-Oceanfy-Event' => 'recebimento',
        ]);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_refunded_charge_is_ignored(): void
    {
        $this->connectAssetPay();
        $order = $this->createAssetPayOrder('CH3', 'completed');

        $this->postJson('/webhooks/gateways/assetpay', $this->charge('CH3', 'DEVOLVIDA', $order->id), [
            'X-Oceanfy-Event' => 'estorno',
        ])->assertOk()->assertJson(['ignored' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_signed_webhook_is_validated(): void
    {
        $this->fakeApi(['/v1/cob/CH4' => ['id' => 'CH4', 'status' => 'CONCLUIDA']]);
        $this->connectAssetPay(['webhook_secret' => 'whsec-test']);
        $order = $this->createAssetPayOrder('CH4');
        $body = json_encode($this->charge('CH4', 'CONCLUIDA', $order->id));
        $ts = (string) time();
        $old = (string) (time() - 3600);

        $this->signedPost($body, $ts, 'sha256='.hash_hmac('sha256', $ts.'.{"tampered":1}', 'whsec-test'))
            ->assertUnauthorized();
        $this->signedPost($body, $old, 'sha256='.hash_hmac('sha256', $old.'.'.$body, 'whsec-test'))
            ->assertUnauthorized();
        $this->assertSame('pending', $order->fresh()->status);

        $this->signedPost($body, $ts, 'sha256='.hash_hmac('sha256', $ts.'.'.$body, 'whsec-test'))->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_transfer_webhook_marks_paid_only_after_api_confirms(): void
    {
        Queue::fake();
        $this->connectAssetPay();
        $withdrawal = $this->createWithdrawal('tx-1');

        $this->fakeApi(['/v1/transfers/tx-1' => ['txId' => 'tx-1', 'status' => 'PROCESSING']]);
        $this->postJson('/webhooks/gateways/assetpay', $this->transfer('TRANSFER', 'tx-1', $withdrawal->id, 'LIQUIDATED'))
            ->assertOk();
        $this->assertSame('pending', $withdrawal->fresh()->status);

        $this->fakeApi(['/v1/transfers/tx-1' => ['txId' => 'tx-1', 'status' => 'LIQUIDATED']], fresh: true);
        $this->postJson('/webhooks/gateways/assetpay', $this->transfer('TRANSFER', 'tx-1', $withdrawal->id, 'LIQUIDATED'))
            ->assertOk();
        $this->assertSame('paid', $withdrawal->fresh()->status);
    }

    public function test_transfer_webhook_finds_withdrawal_by_external_id_and_fails_it(): void
    {
        $this->connectAssetPay();
        $withdrawal = $this->createWithdrawal(null);
        $this->fakeApi(['/v1/transfers/saque-'.$withdrawal->id => ['status' => 'FAILED', 'errorMessage' => 'Chave inexistente']]);

        $this->postJson('/webhooks/gateways/assetpay', $this->transfer('TRANSFER', 'tx-x', $withdrawal->id, 'FAILED'))
            ->assertOk();

        $this->assertSame('failed', $withdrawal->fresh()->status);
    }

    public function test_infraction_opens_and_resolves_med_from_api_data(): void
    {
        $this->connectAssetPay();
        $order = $this->createAssetPayOrder('CH5', 'completed');
        $webhook = [
            'type' => 'INFRACTION',
            'eventAt' => now()->toIso8601String(),
            // Corpo mentiroso de propósito: o estado vem sempre da API.
            'data' => ['id' => 'inf-1', 'status' => 'CLOSED', 'analysisResult' => 'AGREED', 'transaction' => ['id' => 'CH5']],
        ];

        $this->fakeApi(['/v1/infractions/inf-1' => $this->infraction('OPEN', null, 'PENDING')]);
        $this->postJson('/webhooks/gateways/assetpay', $webhook, ['X-Oceanfy-Event' => 'infracoes'])->assertOk();

        $this->assertSame('disputed', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'order_id' => $order->id,
            'cajupay_dispute_id' => 'assetpay:inf-1',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 2900,
        ]);

        $this->fakeApi(['/v1/infractions/inf-1' => $this->infraction('CLOSED', 'DISAGREED', 'FUNDS_RELEASED')], fresh: true);
        $this->postJson('/webhooks/gateways/assetpay', $webhook, ['X-Oceanfy-Event' => 'infracoes'])->assertOk();

        $this->assertDatabaseHas('med_disputes', [
            'cajupay_dispute_id' => 'assetpay:inf-1',
            'status' => MedDispute::STATUS_RESOLVED_WON,
            'outcome' => 'won',
        ]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_driver_creates_pix_charge_in_cents(): void
    {
        $this->fakeApi(['/v1/cob' => ['id' => 'CH9', 'status' => 'ATIVA', 'pix' => ['qrcode' => '000201...']]]);

        $res = (new AssetPayDriver)->createPixPayment(
            $this->creds(),
            29.9,
            ['name' => 'Maria', 'document' => '123.456.789-09', 'email' => 'm@x.com', 'phone' => '(64) 99999-0000'],
            '1234',
            'https://brasapay.com.br/webhooks/gateways/assetpay'
        );

        $this->assertSame('CH9', $res['transaction_id']);
        $this->assertSame('000201...', $res['copy_paste']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/v1/cob'
            && $r['amount'] === 2990
            && $r['externalRef'] === '1234'
            && $r['customer']['document'] === ['number' => '12345678909', 'type' => 'cpf']
            && $r->hasHeader('Authorization', 'Bearer tok-in'));
    }

    public function test_payout_sends_exact_net_with_cash_out_credential(): void
    {
        Queue::fake();
        $this->fakeApi(['/v1/transfers' => ['txId' => 'tx-new', 'status' => 'PROCESSING']]);
        $this->connectAssetPay(['assetpay_admin_fee_payout_brl' => '0,60']);
        $withdrawal = $this->createWithdrawal(null);

        $result = (new AssetPayPayoutService)->sendWithdrawalToPix($withdrawal->fresh(), User::findOrFail($withdrawal->user_id));

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame('tx-new', $result['transaction_id']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/v1/transfers'
            && $r['amount'] === 10000
            && $r['pixKeyType'] === 'email'
            && $r['pixKey'] === 'destino@pix.com'
            && $r['externalId'] === 'saque-'.$withdrawal->id
            && $r->hasHeader('Authorization', 'Bearer tok-out'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/oauth/token' && $r['client_id'] === 'pk_live_out');
    }

    public function test_payout_treats_duplicate_external_id_as_the_original_transfer(): void
    {
        Http::fake([
            self::API.'/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::API.'/v1/transfers' => Http::response(['statusCode' => 409, 'transfer' => ['txId' => 'tx-old', 'status' => 'LIQUIDATED']], 409),
        ]);

        $res = (new AssetPayDriver)->createPixCashout($this->creds(), 50, '64999990000', 'phone', '7', '');

        $this->assertTrue($res['ok']);
        $this->assertSame('tx-old', $res['transaction_id']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API.'/v1/transfers' && $r['pixKey'] === '+5564999990000');
    }

    public function test_payout_reports_immediate_bank_refusal(): void
    {
        Http::fake([
            self::API.'/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::API.'/v1/transfers' => Http::response(['txId' => 'tx-f', 'status' => 'FAILED', 'errorMessage' => 'Conta encerrada'], 201),
        ]);

        $res = (new AssetPayDriver)->createPixCashout($this->creds(), 50, 'a@b.com', 'email', '8', '');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Conta encerrada', $res['error']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $routes
     */
    private function fakeApi(array $routes, bool $fresh = false): void
    {
        if ($fresh) {
            // Recria a fábrica: as respostas novas valem a partir daqui.
            Http::swap(new \Illuminate\Http\Client\Factory);
        }
        $fakes = [
            self::API.'/oauth/token' => function (HttpRequest $r) {
                return Http::response([
                    'access_token' => $r['client_id'] === 'pk_live_out' ? 'tok-out' : 'tok-in',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ]);
            },
        ];
        foreach ($routes as $path => $body) {
            $fakes[self::API.$path] = Http::response($body, 200);
        }
        Http::fake($fakes);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function creds(array $extra = []): array
    {
        return array_merge([
            'public_key' => 'pk_live_in',
            'secret_key' => 'sk_live_in',
            'cashout_public_key' => 'pk_live_out',
            'cashout_secret_key' => 'sk_live_out',
        ], $extra);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function connectAssetPay(array $extra = []): void
    {
        $cred = GatewayCredential::query()->firstOrNew(['tenant_id' => null, 'gateway_slug' => 'assetpay']);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials($this->creds($extra));
        $cred->save();
    }

    private function createAssetPayOrder(string $gatewayId, string $status = 'pending'): Order
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        return Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => $status,
            'amount' => 29,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'gateway' => 'assetpay',
            'gateway_id' => $gatewayId,
        ]);
    }

    private function createWithdrawal(?string $txId): Withdrawal
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => ['payout_pix_key' => 'destino@pix.com', 'payout_pix_key_type' => 'email'],
        ])->save();

        return Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 100,
            'fee_amount' => 0,
            'net_amount' => 100,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
            'payout_provider' => 'assetpay',
            'payout_external_id' => $txId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(string $id, string $status, int $orderId): array
    {
        return [
            'id' => $id,
            'amount' => 2900,
            'paymentMethod' => 'pix',
            'status' => $status,
            'externalRef' => (string) $orderId,
            'customer' => ['name' => 'Maria', 'document' => ['number' => '12345678909', 'type' => 'cpf']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transfer(string $type, string $txId, int $withdrawalId, string $status): array
    {
        return [
            'type' => $type,
            'eventAt' => now()->toIso8601String(),
            'data' => ['txId' => $txId, 'externalId' => 'saque-'.$withdrawalId, 'status' => $status],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function infraction(string $status, ?string $analysis, string $outcome): array
    {
        return [
            'id' => 'inf-1',
            'status' => $status,
            'analysisResult' => $analysis,
            'outcome' => $outcome,
            'infractionType' => 'FRAUD',
            'reportDetails' => 'Golpe',
            'amount' => 2900,
            'currency' => 'BRL',
            'blocked' => $status === 'OPEN',
            'endToEndId' => 'E123',
            'transaction' => ['type' => 'CHARGE', 'id' => 'CH5', 'externalRef' => null],
        ];
    }

    private function signedPost(string $body, string $ts, string $signature)
    {
        return $this->call('POST', '/webhooks/gateways/assetpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_OCEANFY_EVENT' => 'recebimento',
            'HTTP_X_OCEANFY_TIMESTAMP' => $ts,
            'HTTP_X_OCEANFY_SIGNATURE' => $signature,
        ], $body);
    }
}
