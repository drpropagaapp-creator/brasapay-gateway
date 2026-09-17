<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsaasWebhookChargePaidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }
    private function seedAsaas(string $authToken = 'asaas-auth-token-32-chars-minimum'): void
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'asaas',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'api_key' => '$aact_hmlg_test',
            'sandbox' => true,
            'webhook_secret' => $authToken,
        ]);
        $cred->save();
    }

    private function createPendingOrder(string $paymentId): Order
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        return Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'asaas',
            'gateway_id' => $paymentId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $event, string $paymentId, string $status = 'RECEIVED'): array
    {
        return [
            'event' => $event,
            'payment' => [
                'object' => 'payment',
                'id' => $paymentId,
                'status' => $status,
            ],
        ];
    }

    public function test_webhook_completes_order_on_payment_received(): void
    {
        $this->seedAsaas();
        Http::fake([
            'api-sandbox.asaas.com/v3/lean/payments/pay_ok' => Http::response([
                'id' => 'pay_ok',
                'status' => 'RECEIVED',
            ], 200),
        ]);

        $order = $this->createPendingOrder('pay_ok');

        $this->withHeaders(['asaas-access-token' => 'asaas-auth-token-32-chars-minimum'])
            ->postJson('/webhooks/gateways/asaas', $this->payload('PAYMENT_RECEIVED', 'pay_ok'))
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_webhook_rejects_missing_auth_token(): void
    {
        $this->seedAsaas();
        $order = $this->createPendingOrder('pay_unauth');

        $this->postJson('/webhooks/gateways/asaas', $this->payload('PAYMENT_RECEIVED', 'pay_unauth'))
            ->assertUnauthorized();

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_webhook_cancels_pending_order_on_payment_deleted(): void
    {
        $this->seedAsaas();
        Http::fake([
            'api-sandbox.asaas.com/v3/lean/payments/pay_del' => Http::response([
                'id' => 'pay_del',
                'status' => 'PENDING',
                'deleted' => true,
            ], 200),
        ]);

        $order = $this->createPendingOrder('pay_del');

        $this->withHeaders(['asaas-access-token' => 'asaas-auth-token-32-chars-minimum'])
            ->postJson('/webhooks/gateways/asaas', $this->payload('PAYMENT_DELETED', 'pay_del', 'PENDING'))
            ->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_webhook_marks_completed_order_disputed_on_chargeback(): void
    {
        $this->seedAsaas();
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 40,
            'email' => 'buyer@test.com',
            'gateway' => 'asaas',
            'gateway_id' => 'pay_cb',
        ]);

        $this->withHeaders(['asaas-access-token' => 'asaas-auth-token-32-chars-minimum'])
            ->postJson('/webhooks/gateways/asaas', $this->payload('PAYMENT_CHARGEBACK_REQUESTED', 'pay_cb', 'CHARGEBACK_REQUESTED'))
            ->assertOk();

        $this->assertSame('disputed', $order->fresh()->status);
    }
}
