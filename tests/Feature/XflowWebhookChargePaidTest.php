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

class XflowWebhookChargePaidTest extends TestCase
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

    public function test_webhook_completes_order_when_paid_and_api_confirms(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges/clx_charge_1' => Http::response([
                'id' => 'clx_charge_1',
                'livemode' => true,
                'status' => 'paid',
                'amountCents' => 1000,
            ], 200),
        ]);

        $secret = 'xflow-whsec-test';
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'xflow',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_live_test',
            'secret_key' => 'sk_live_test',
            'webhook_secret' => $secret,
        ]);
        $cred->save();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'xflow',
            'gateway_id' => 'clx_charge_1',
            'payment_method' => 'pix',
        ]);

        $response = $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', [
            'event' => 'transaction.paid',
            'data' => [
                'id' => 'clx_charge_1',
                'livemode' => true,
                'status' => 'paid',
                'amountCents' => 1000,
            ],
            'createdAt' => '2026-09-12T18:00:00.000Z',
        ], $secret);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);

        GatewayCredential::query()->where('gateway_slug', 'xflow')->delete();
    }

    public function test_webhook_ignores_sandbox_event_when_credentials_are_live(): void
    {
        $secret = 'xflow-whsec-test';
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'xflow',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_live_prod',
            'secret_key' => 'sk_live_prod',
            'webhook_secret' => $secret,
        ]);
        $cred->save();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'xflow',
            'gateway_id' => 'clx_test_1',
            'payment_method' => 'pix',
        ]);

        $response = $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', [
            'event' => 'transaction.paid',
            'data' => [
                'id' => 'clx_test_1',
                'livemode' => false,
                'status' => 'paid',
            ],
        ], $secret);

        $response->assertOk()->assertJson(['ignored' => true]);
        $this->assertSame('pending', $order->fresh()->status);

        GatewayCredential::query()->where('gateway_slug', 'xflow')->delete();
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'xflow',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_live_test',
            'secret_key' => 'sk_live_test',
            'webhook_secret' => 'correct-secret',
        ]);
        $cred->save();

        Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'xflow',
            'gateway_id' => 'clx_charge_2',
            'payment_method' => 'pix',
        ]);

        $response = $this->postSignedGatewayWebhook('/webhooks/gateways/xflow', [
            'event' => 'transaction.paid',
            'data' => ['id' => 'clx_charge_2', 'livemode' => true, 'status' => 'paid'],
        ], 'wrong-secret');

        $response->assertStatus(401);

        GatewayCredential::query()->where('gateway_slug', 'xflow')->delete();
    }
}
