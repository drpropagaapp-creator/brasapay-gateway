<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssetPayWebhookTest extends TestCase
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

    private function connectAssetPay(array $extraCredentials = []): void
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'assetpay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials(array_merge([
            'secret_key' => 'sk-test',
            'public_key' => 'pk-test',
        ], $extraCredentials));
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

    public function test_transaction_paid_webhook_completes_order_after_api_reconfirm(): void
    {
        Http::fake([
            'https://api.assetpay.com.br/api/v1/transactions/CMR123' => Http::response([
                'id' => 'CMR123',
                'status' => 'paid',
            ], 200),
        ]);

        $this->connectAssetPay();
        $order = $this->createAssetPayOrder('CMR123');

        $response = $this->postJson('/webhooks/gateways/assetpay', [
            'event' => 'transaction.paid',
            'transaction' => [
                'id' => 'CMR123',
                'amount' => 2900,
                'status' => 'paid',
            ],
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_transaction_created_webhook_is_ignored(): void
    {
        $this->connectAssetPay();
        $order = $this->createAssetPayOrder('CMR456');

        $response = $this->postJson('/webhooks/gateways/assetpay', [
            'event' => 'transaction.created',
            'transaction' => ['id' => 'CMR456', 'status' => 'processing'],
        ]);

        $response->assertOk()->assertJson(['received' => true, 'ignored' => true]);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_infraction_webhook_creates_med_dispute_and_marks_order_disputed(): void
    {
        $this->connectAssetPay();
        $order = $this->createAssetPayOrder('CMR789', 'completed');

        $response = $this->postJson('/webhooks/gateways/assetpay', [
            'event' => 'transaction.infraction',
            'transaction' => [
                'id' => 'CMR789',
                'status' => 'blocked',
                'blocked' => true,
            ],
            'infraction' => [
                'id' => 'INF-001',
                'status' => 'OPEN',
                'type' => 'MED',
                'amount' => 29.0,
                'currency' => 'BRL',
                'end_to_end_id' => 'E123456789',
                'provider' => 'AssetFinance',
            ],
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('disputed', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'order_id' => $order->id,
            'cajupay_dispute_id' => 'assetpay:INF-001',
            'status' => MedDispute::STATUS_OPEN,
            'responsible_party' => MedDispute::PARTY_TENANT,
        ]);
    }

    public function test_infraction_closed_webhook_resolves_dispute_as_won_and_releases_order(): void
    {
        $this->connectAssetPay();
        $order = $this->createAssetPayOrder('CMR790', 'completed');

        $this->postJson('/webhooks/gateways/assetpay', [
            'event' => 'transaction.infraction',
            'transaction' => ['id' => 'CMR790', 'status' => 'blocked'],
            'infraction' => ['id' => 'INF-002', 'status' => 'OPEN', 'type' => 'MED', 'amount' => 29.0],
        ])->assertOk();

        $this->postJson('/webhooks/gateways/assetpay', [
            'event' => 'transaction.infraction.closed',
            'transaction' => ['id' => 'CMR790', 'status' => 'paid', 'blocked' => false],
            'infraction' => ['id' => 'INF-002', 'status' => 'CLOSED', 'analysis_result' => 'DISAGREED'],
        ])->assertOk();

        $this->assertDatabaseHas('med_disputes', [
            'cajupay_dispute_id' => 'assetpay:INF-002',
            'status' => MedDispute::STATUS_RESOLVED_WON,
            'outcome' => 'won',
        ]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_webhook_rejects_invalid_hmac_when_secret_configured(): void
    {
        $this->connectAssetPay(['webhook_secret' => 'real-secret']);
        $this->createAssetPayOrder('CMR999');

        $payload = [
            'event' => 'transaction.paid',
            'transaction' => ['id' => 'CMR999', 'status' => 'paid'],
        ];

        $response = $this->postJson('/webhooks/gateways/assetpay', $payload, [
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode(['tampered' => true]), 'real-secret'),
        ]);

        $response->assertUnauthorized();
    }
}
