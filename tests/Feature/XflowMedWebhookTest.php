<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\ApiApplication;
use App\Models\GatewayCredential;
use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class XflowMedWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'queue.default' => 'sync',
            'getfy.api.inbound_webhooks_async' => false,
        ]);
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    protected function tearDown(): void
    {
        GatewayCredential::query()->where('gateway_slug', 'xflow')->delete();
        parent::tearDown();
    }

    public function test_dispute_opened_creates_platform_dispute_without_marking_checkout_order(): void
    {
        if (! Schema::hasTable('med_disputes')) {
            $this->markTestSkipped('med_disputes table');
        }

        [$order, $secret] = $this->makeXflowPaidOrder();

        $this->postSignedGatewayWebhook('/webhooks/gateways/xflow/disputes', $this->openedPayload('clx_med_1'), $secret)
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'order_id' => $order->id,
            'cajupay_dispute_id' => 'xflow:dsp_abc123',
            'status' => MedDispute::STATUS_OPEN,
            'responsible_party' => MedDispute::PARTY_PLATFORM,
            'reason_code' => 'FRAUD',
        ]);
    }

    public function test_dispute_opened_marks_api_pix_order_disputed(): void
    {
        if (! Schema::hasTable('med_disputes')) {
            $this->markTestSkipped('med_disputes table');
        }

        [$order, $secret] = $this->makeXflowPaidOrder(apiPix: true);

        $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', $this->openedPayload('clx_med_1'), $secret)
            ->assertOk();

        $this->assertSame('disputed', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'order_id' => $order->id,
            'cajupay_dispute_id' => 'xflow:dsp_abc123',
            'responsible_party' => MedDispute::PARTY_TENANT,
        ]);
    }

    public function test_dispute_accepted_closes_open_dispute(): void
    {
        if (! Schema::hasTable('med_disputes')) {
            $this->markTestSkipped('med_disputes table');
        }

        [$order, $secret] = $this->makeXflowPaidOrder();
        $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', $this->openedPayload('clx_med_1'), $secret);

        $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', [
            'event' => 'dispute.accepted',
            'data' => [
                'id' => 'dsp_abc123',
                'charge_id' => 'clx_med_1',
                'livemode' => true,
                'status' => 'accepted',
                'amountCents' => 2500,
            ],
        ], $secret)->assertOk();

        $this->assertDatabaseHas('med_disputes', [
            'cajupay_dispute_id' => 'xflow:dsp_abc123',
            'status' => MedDispute::STATUS_RESOLVED_WON,
            'outcome' => 'won',
        ]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_dispute_rejected_refunds_tenant_managed_order(): void
    {
        if (! Schema::hasTable('med_disputes') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('med or wallet tables');
        }

        [$order, $secret] = $this->makeXflowPaidOrder(apiPix: true);
        $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', $this->openedPayload('clx_med_1'), $secret);
        $this->assertSame('disputed', $order->fresh()->status);

        $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', [
            'event' => 'dispute.rejected',
            'data' => [
                'id' => 'dsp_abc123',
                'charge_id' => 'clx_med_1',
                'livemode' => true,
                'status' => 'rejected',
                'amountCents' => 2500,
            ],
        ], $secret)->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'cajupay_dispute_id' => 'xflow:dsp_abc123',
            'status' => MedDispute::STATUS_RESOLVED_LOST,
            'outcome' => 'lost',
        ]);
    }

    public function test_dispute_opened_is_idempotent(): void
    {
        if (! Schema::hasTable('med_disputes')) {
            $this->markTestSkipped('med_disputes table');
        }

        [$order, $secret] = $this->makeXflowPaidOrder();
        $payload = $this->openedPayload('clx_med_1');
        $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', $payload, $secret)->assertOk();
        $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', $payload, $secret)->assertOk();

        $this->assertSame(1, MedDispute::query()->where('order_id', $order->id)->count());
    }

    /**
     * @return array{0: Order, 1: string}
     */
    private function makeXflowPaidOrder(bool $apiPix = false): array
    {
        $secret = 'xflow-med-whsec-test';
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'xflow',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_live_med',
            'secret_key' => 'sk_live_med',
            'webhook_secret' => $secret,
        ]);
        $cred->save();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $meta = [];
        $apiApplicationId = null;
        if ($apiPix) {
            $app = ApiApplication::create([
                'tenant_id' => 1,
                'name' => 'API MED Xflow',
                'slug' => ApiApplication::generateUniqueSlug(1, 'API MED Xflow'),
                'api_key_hash' => hash('sha256', 'k'),
                'public_key' => ApiApplication::generatePublicKey(),
                'secret_key_hash' => hash('sha256', 's'),
                'payment_gateways' => ApiApplication::defaultPaymentGateways(),
                'allowed_ips' => [],
                'is_active' => true,
                'is_legacy' => true,
                'scopes' => [],
            ]);
            $apiApplicationId = $app->id;
            $meta['source'] = 'api';
        }

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'api_application_id' => $apiApplicationId,
            'status' => 'completed',
            'amount' => 25,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'gateway' => 'xflow',
            'gateway_id' => 'clx_med_1',
            'metadata' => $meta,
        ]);

        return [$order, $secret];
    }

    /**
     * @return array<string, mixed>
     */
    private function openedPayload(string $chargeId): array
    {
        return [
            'event' => 'dispute.opened',
            'data' => [
                'id' => 'dsp_abc123',
                'charge_id' => $chargeId,
                'livemode' => true,
                'status' => 'opened',
                'amountCents' => 2500,
                'reason_code' => 'FRAUD',
                'reason' => 'Pagamento contestado pelo pagador',
                'txid' => 'E17028875202604301400ABC123',
            ],
            'createdAt' => '2026-09-12T18:00:00.000Z',
        ];
    }
}
