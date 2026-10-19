<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\CheckoutSession;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayPalCheckoutCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);
    }

    public function test_display_currency_eur_still_creates_paypal_order_in_brl(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'price' => 9.99,
            'currency' => 'BRL',
        ]);

        $paypal = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'paypal',
            'is_connected' => true,
        ]);
        $paypal->setEncryptedCredentials([
            'client_id' => 'paypal_client',
            'client_secret' => 'paypal_secret',
            'sandbox' => true,
        ]);
        $paypal->save();

        $session = CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'checkout_slug' => 'chkppbrl',
            'session_token' => (string) Str::uuid(),
            'step' => CheckoutSession::STEP_VISIT,
            'customer_ip' => '127.0.0.1',
        ]);
        $session->forceFill([
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ])->save();

        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
            ]),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-ORDER-BRL',
                'status' => 'CREATED',
            ], 201),
        ]);

        $response = $this->postJson(route('checkout.paypal.create-order'), [
            'product_id' => $product->id,
            'email' => 'buyer-paypal@example.com',
            'name' => 'Ana Silva',
            'phone' => '11999999999',
            'checkout_session_token' => $session->session_token,
            'display_currency' => 'EUR',
            'checkout_locale' => 'en',
        ]);

        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJson([
            'success' => true,
            'paypal_order_id' => 'PAYPAL-ORDER-BRL',
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v2/checkout/orders')) {
                return false;
            }
            $body = $request->data();
            $amount = $body['purchase_units'][0]['amount'] ?? [];

            return ($amount['currency_code'] ?? '') === 'BRL'
                && ($amount['value'] ?? '') === '9.99'
                && ($body['application_context']['locale'] ?? '') === 'en-US';
        });

        $order = Order::query()->where('gateway', 'paypal')->where('email', 'buyer-paypal@example.com')->first();
        $this->assertNotNull($order);
        $this->assertSame('9.99', number_format((float) $order->amount, 2, '.', ''));
    }
}
