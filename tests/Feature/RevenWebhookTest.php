<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RevenWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'sync',
            'getfy.api.inbound_webhooks_async' => false,
        ]);
        Event::fake();
    }

    private function connectReven(): void
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'reven',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials(['api_key' => 'rvn-test-key']);
        $cred->save();
    }

    private function createRevenOrder(string $gatewayId): Order
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        return Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 199.9,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'gateway' => 'reven',
            'gateway_id' => $gatewayId,
        ]);
    }

    public function test_paid_callback_completes_order_after_api_reconfirm(): void
    {
        Http::fake([
            'https://app.revenpayments.com.br/api/gateway/transactions*' => Http::response([
                'success' => true,
                'data' => [
                    ['id' => 'rvn-tx-1', 'status' => 'PAID', 'amount' => 199.9],
                ],
            ], 200),
        ]);

        $this->connectReven();
        $order = $this->createRevenOrder('rvn-tx-1');

        $response = $this->postJson('/webhooks/gateways/reven', [
            'transactionId' => 'rvn-tx-1',
            'status' => 'PAID',
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_pending_callback_is_ignored(): void
    {
        $this->connectReven();
        $order = $this->createRevenOrder('rvn-tx-2');

        $response = $this->postJson('/webhooks/gateways/reven', [
            'transactionId' => 'rvn-tx-2',
            'status' => 'PENDING',
        ]);

        $response->assertOk()->assertJson(['received' => true, 'ignored' => true]);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_paid_callback_does_not_complete_when_api_says_pending(): void
    {
        Http::fake([
            'https://app.revenpayments.com.br/api/gateway/transactions*' => Http::response([
                'success' => true,
                'data' => [
                    ['id' => 'rvn-tx-3', 'status' => 'PENDING'],
                ],
            ], 200),
        ]);

        $this->connectReven();
        $order = $this->createRevenOrder('rvn-tx-3');

        $this->postJson('/webhooks/gateways/reven', [
            'transactionId' => 'rvn-tx-3',
            'status' => 'PAID',
        ])->assertOk();

        // Callback dizia paid, mas a API (fonte de verdade) diz pending — pedido não libera.
        $this->assertSame('pending', $order->fresh()->status);
    }
}
