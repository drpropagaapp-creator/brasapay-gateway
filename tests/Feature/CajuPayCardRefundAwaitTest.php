<?php

namespace Tests\Feature;

use App\Events\OrderRefunded;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\OrderRefundGatewayBridge;
use App\Services\Platform\PlatformAdminAlertCounts;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CajuPayCardRefundAwaitTest extends TestCase
{
    private const SESSION_ID = '550e8400-e29b-41d4-a716-446655440211';

    private const PAYMENT_ID = '550e8400-e29b-41d4-a716-446655440212';

    private const SIGNING_SECRET = 'cwhsec_card_refund_await_32chars_xx';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);
        Event::fake([OrderRefunded::class]);
    }

    public function test_bridge_marks_card_refund_pending_without_calling_caju(): void
    {
        Http::fake();

        $merchant = $this->createMerchantWithWallet();
        $order = $this->createCajuCardOrder($merchant);

        $result = app(OrderRefundGatewayBridge::class)->tryRefund($order);

        $this->assertSame('gateway_pending', $result['status']);
        $this->assertStringContainsString('painel da CajuPay', (string) $result['note']);
        $this->assertTrue((bool) ($order->fresh()->metadata['cajupay_card_refund_pending'] ?? false));
        $this->assertSame('awaiting_panel', $order->fresh()->metadata['cajupay_card_refund_status'] ?? null);
        Http::assertNothingSent();
    }

    public function test_seller_refund_debits_wallet_and_waits_for_caju_panel(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Http::fake();

        $merchant = $this->createMerchantWithWallet();
        $this->connectCajuPay();
        $order = $this->createCajuCardOrder($merchant);
        $this->creditSale($merchant, $order);

        $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order), [
            'reason' => 'Cliente pediu estorno',
        ])->assertOk()->assertJson([
            'success' => true,
            'gateway_status' => 'gateway_pending',
        ]);

        $order->refresh();
        $this->assertSame('refund_pending', $order->status);
        $this->assertTrue((bool) ($order->metadata['cajupay_card_refund_pending'] ?? false));

        $wallet = TenantWallet::query()->where('tenant_id', $merchant->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->available_card);
        $this->assertTrue(
            WalletTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_DEBIT_REFUND)
                ->exists()
        );

        $request = RefundRequest::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($request);
        $this->assertSame(RefundRequest::STATUS_APPROVED, $request->status);
        $this->assertSame('gateway_pending', $request->gateway_refund_status);
        $this->assertSame('Cliente pediu estorno', $request->customer_reason);
        $this->assertStringContainsString('painel da CajuPay', (string) $request->gateway_refund_note);

        Event::assertNotDispatched(OrderRefunded::class);
        Http::assertNothingSent();
    }

    public function test_caju_refunded_webhook_finalizes_pending_card_refund(): void
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Http::fake();

        $merchant = $this->createMerchantWithWallet();
        $this->connectCajuPay();
        $order = $this->createCajuCardOrder($merchant);
        $this->creditSale($merchant, $order);

        $this->actingAs($merchant)->postJson(route('vendas.refund-manually', $order), [
            'reason' => 'Estorno cartão Caju',
        ])->assertOk();

        $this->assertSame('refund_pending', $order->fresh()->status);

        $counts = app(PlatformAdminAlertCounts::class);
        $this->assertGreaterThanOrEqual(1, $counts->refundPendingOrdersCount());
        $this->assertGreaterThanOrEqual(1, $counts->refundRequestsPendingCount());

        $this->postCajuRefundedWebhook()->assertOk();

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertArrayNotHasKey('cajupay_card_refund_pending', $order->metadata ?? []);
        $this->assertSame('refunded', $order->metadata['cajupay_card_refund_status'] ?? null);

        $request = RefundRequest::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($request);
        $this->assertSame(RefundRequest::STATUS_APPROVED, $request->status);
        $this->assertSame('gateway_ok', $request->gateway_refund_status);
        $this->assertStringContainsString('confirmado', (string) $request->gateway_refund_note);

        Event::assertDispatched(OrderRefunded::class);
        $this->assertSame(0, $counts->refundPendingOrdersCount());
    }

    private function connectCajuPay(): void
    {
        $cred = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
            'checkout_webhook_signing_secret' => self::SIGNING_SECRET,
        ]);
        $cred->save();
    }

    private function postCajuRefundedWebhook()
    {
        $raw = json_encode([
            'id' => 'evt-card-refund-1',
            'type' => 'checkout.payment.refunded',
            'api_version' => '2026-05-09',
            'created' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => [
                'object' => [
                    'gateway' => 'cajupay',
                    'cajupay_charge_id' => self::PAYMENT_ID,
                    'checkout_session_id' => self::SESSION_ID,
                    'amount_cents' => 10000,
                    'currency' => 'brl',
                ],
            ],
        ]);
        $this->assertIsString($raw);
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, self::SIGNING_SECRET);

        return $this->call('POST', route('webhooks.cajupay.checkout'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$ts.',v1='.$sig,
        ], $raw);
    }

    private function createMerchantWithWallet(): User
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
            'available_balance' => 95.0,
            'pending_balance' => 0,
            'currency' => 'BRL',
            'available_pix' => 0,
            'available_card' => 95.0,
            'available_boleto' => 0,
            'pending_pix' => 0,
            'pending_card' => 0,
            'pending_boleto' => 0,
        ]);

        return $merchant;
    }

    private function createCajuCardOrder(User $merchant): Order
    {
        $customer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $merchant->id]);

        return Order::create([
            'tenant_id' => $merchant->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.0,
            'gateway' => 'cajupay',
            'gateway_id' => self::SESSION_ID,
            'payment_method' => 'card',
            'email' => $customer->email,
            'metadata' => [
                'cajupay_checkout_session_id' => self::SESSION_ID,
                'cajupay_payment_id' => self::PAYMENT_ID,
            ],
        ]);
    }

    private function creditSale(User $merchant, Order $order): void
    {
        WalletTransaction::create([
            'tenant_id' => $merchant->id,
            'order_id' => $order->id,
            'bucket' => 'card',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100.00,
            'amount_fee' => 5.00,
            'amount_net' => 95.00,
        ]);
    }
}
