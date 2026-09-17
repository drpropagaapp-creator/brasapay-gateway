<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\OrderRefundGatewayBridge;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class XflowRefundTest extends TestCase
{
    private const CHARGE_ID = 'clx_refund_charge_1';

    private const WEBHOOK_SECRET = 'xflow-whsec-refund-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_refund_bridge_calls_charges_refund_api(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges/'.self::CHARGE_ID.'/refund' => Http::response([
                'id' => 'rf_ok',
                'chargeId' => self::CHARGE_ID,
                'status' => 'completed',
                'amountCents' => 1990,
            ], 200),
        ]);

        $this->connectXflow();
        $order = $this->createXflowOrder($this->makeUser(), 'completed');

        $result = app(OrderRefundGatewayBridge::class)->tryRefund($order);

        $this->assertSame('gateway_ok', $result['status']);
        $order->refresh();
        $this->assertSame('completed', $order->metadata['xflow_refund_status'] ?? null);
        $this->assertFalse((bool) ($order->metadata['xflow_refund_pending'] ?? true));

        Http::assertSent(fn ($req) => $req->method() === 'POST'
            && str_ends_with($req->url(), '/charges/'.self::CHARGE_ID.'/refund'));
    }

    public function test_refund_bridge_maps_202_as_pending(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges/'.self::CHARGE_ID.'/refund' => Http::response([
                'id' => 'rf_pending',
                'chargeId' => self::CHARGE_ID,
                'status' => 'pending',
                'amountCents' => 1000,
            ], 202),
        ]);

        $this->connectXflow();
        $order = $this->createXflowOrder($this->makeUser(), 'completed');

        $result = app(OrderRefundGatewayBridge::class)->tryRefund($order);

        $this->assertSame('gateway_pending', $result['status']);
        $order->refresh();
        $this->assertTrue((bool) ($order->metadata['xflow_refund_pending'] ?? false));
        $this->assertSame('rf_pending', $order->metadata['xflow_refund_id'] ?? null);
    }

    public function test_refund_bridge_maps_window_expired(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges/'.self::CHARGE_ID.'/refund' => Http::response([
                'error' => 'refund_window_expired',
                'message' => 'Refund window expired',
            ], 422),
        ]);

        $this->connectXflow();
        $order = $this->createXflowOrder($this->makeUser(), 'completed');

        $result = app(OrderRefundGatewayBridge::class)->tryRefund($order);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('refund_window_expired', $result['error_code'] ?? null);
        $this->assertStringContainsString('90 dias', $result['note'] ?? '');
    }

    public function test_seller_refund_stays_pending_until_xflow_confirms(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Http::fake([
            'app.xflowpayments.com/api/v1/charges/'.self::CHARGE_ID.'/refund' => Http::response([
                'id' => 'rf_pending',
                'chargeId' => self::CHARGE_ID,
                'status' => 'pending',
                'amountCents' => 10000,
            ], 202),
        ]);

        $this->connectXflow();
        $merchant = $this->createMerchantWithWallet(95.0);
        $order = $this->createXflowOrder($merchant, 'completed');
        $this->creditSale($merchant, $order);

        $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order), [
            'reason' => 'Cliente pediu estorno',
        ])->assertOk()->assertJson(['success' => true]);

        $order->refresh();
        $this->assertSame('refund_pending', $order->status);
        $this->assertTrue((bool) ($order->metadata['xflow_refund_pending'] ?? false));

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->available_pix);
    }

    public function test_webhook_refunded_finalizes_pending_order(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges/'.self::CHARGE_ID => Http::response([
                'id' => self::CHARGE_ID,
                'livemode' => true,
                'status' => 'refunded',
                'refund' => ['id' => 'rf_1', 'status' => 'completed'],
            ], 200),
        ]);

        $this->connectXflow(withWebhookSecret: true);
        $order = $this->createXflowOrder($this->makeUser(), 'refund_pending');

        $response = $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', [
            'event' => 'transaction.refunded',
            'data' => [
                'id' => self::CHARGE_ID,
                'livemode' => true,
                'status' => 'refunded',
            ],
        ], self::WEBHOOK_SECRET);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_webhook_refund_failed_restores_pending_order_and_wallet(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Http::fake([
            'app.xflowpayments.com/api/v1/charges/'.self::CHARGE_ID => Http::response([
                'id' => self::CHARGE_ID,
                'livemode' => true,
                'status' => 'paid',
                'refund' => [
                    'id' => 'rf_fail',
                    'status' => 'failed',
                    'failureReason' => 'PSP recusou a devolução',
                ],
            ], 200),
        ]);

        $this->connectXflow(withWebhookSecret: true);
        $merchant = $this->createMerchantWithWallet(0.0);
        $order = $this->createXflowOrder($merchant, 'completed');
        $this->creditSale($merchant, $order);
        $order->update([
            'status' => 'refund_pending',
            'metadata' => [
                'xflow_refund_pending' => true,
                'xflow_refund_status' => 'pending',
            ],
        ]);
        WalletTransaction::query()->create([
            'tenant_id' => $merchant->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_DEBIT_REFUND,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);

        $response = $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', [
            'event' => 'transaction.refund_failed',
            'data' => [
                'id' => self::CHARGE_ID,
                'livemode' => true,
                'status' => 'paid',
                'refund' => [
                    'status' => 'failed',
                    'failureReason' => 'PSP recusou a devolução',
                ],
            ],
        ], self::WEBHOOK_SECRET);

        $response->assertOk()->assertJson(['received' => true]);
        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertFalse((bool) ($order->metadata['xflow_refund_pending'] ?? true));
        $this->assertSame('failed', $order->metadata['xflow_refund_status'] ?? null);

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertEquals(95.0, (float) $wallet->available_pix);
    }

    private function makeUser(): User
    {
        return User::factory()->create(['tenant_id' => 1]);
    }

    private function connectXflow(bool $withWebhookSecret = false): void
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'xflow',
        ]);
        $cred->is_connected = true;
        $payload = [
            'public_key' => 'pk_live_xflow',
            'secret_key' => 'sk_live_xflow',
        ];
        if ($withWebhookSecret) {
            $payload['webhook_secret'] = self::WEBHOOK_SECRET;
        }
        $cred->setEncryptedCredentials($payload);
        $cred->save();
    }

    private function createXflowOrder(User $owner, string $status): Order
    {
        $tenantId = (int) ($owner->tenant_id ?? $owner->id);
        $product = $this->createTestProduct(['tenant_id' => $tenantId]);

        return Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'status' => $status,
            'amount' => 100,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'gateway' => 'xflow',
            'gateway_id' => self::CHARGE_ID,
        ]);
    }

    private function createMerchantWithWallet(float $availablePix = 95.0): User
    {
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        TenantWallet::query()->create([
            'tenant_id' => $merchant->id,
            'available_balance' => $availablePix,
            'pending_balance' => 0,
            'currency' => 'BRL',
            'available_pix' => $availablePix,
            'available_card' => 0,
            'available_boleto' => 0,
            'pending_pix' => 0,
            'pending_card' => 0,
            'pending_boleto' => 0,
        ]);

        return $merchant;
    }

    private function creditSale(User $merchant, Order $order): void
    {
        WalletTransaction::create([
            'tenant_id' => $merchant->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);
    }
}
