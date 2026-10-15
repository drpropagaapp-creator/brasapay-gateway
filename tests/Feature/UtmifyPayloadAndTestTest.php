<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UtmifyIntegration;
use App\Models\User;
use App\Services\UtmifyService;
use App\Support\BrazilianDocuments;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UtmifyPayloadAndTestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    private function createInfoprodutor(): User
    {
        return User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ]);
    }

    public function test_build_payload_uses_getfy_platform_name(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'gateway' => 'efi',
            'payment_method' => 'card',
        ]);
        $order->id = 456;
        $order->created_at = now();
        $order->updated_at = now();

        $service = new UtmifyService;
        $payload = $service->buildPayload($order, 'paid', []);

        $this->assertSame(config('getfy.app_name', 'Getfy'), $payload['platform']);
    }

    public function test_build_payload_maps_payment_method_from_order_not_gateway(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'gateway' => 'efi',
            'payment_method' => 'card',
        ]);
        $order->id = 789;
        $order->created_at = now();
        $order->updated_at = now();

        $service = new UtmifyService;
        $payload = $service->buildPayload($order, 'paid', []);

        $this->assertSame('credit_card', $payload['paymentMethod']);
    }

    public function test_build_payload_always_includes_all_seven_tracking_keys(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'metadata' => [
                'utm_source' => 'facebook',
                'utm_campaign' => 'promo',
            ],
        ]);
        $order->id = 999;
        $order->created_at = now();
        $order->updated_at = now();

        $payload = (new UtmifyService)->buildPayload($order, 'paid', []);
        $tracking = $payload['trackingParameters'];

        foreach (\App\Models\CheckoutSession::TRACKING_FIELD_KEYS as $key) {
            $this->assertArrayHasKey($key, $tracking, "Missing tracking key: {$key}");
        }

        $this->assertSame('facebook', $tracking['utm_source']);
        $this->assertSame('promo', $tracking['utm_campaign']);
        $this->assertNull($tracking['utm_medium']);
        $this->assertNull($tracking['utm_content']);
        $this->assertNull($tracking['utm_term']);
        $this->assertNull($tracking['sck']);
        $this->assertNull($tracking['src']);
    }

    public function test_test_endpoint_sends_is_test_payload(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        $user = $this->createInfoprodutor();

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMIFY test',
            'api_key' => 'test-api-key-utmify',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('integrations.utmify.test', $integration))
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $tracking = $body['trackingParameters'] ?? [];

            foreach (\App\Models\CheckoutSession::TRACKING_FIELD_KEYS as $key) {
                if (! array_key_exists($key, $tracking)) {
                    return false;
                }
            }

            return $request->url() === 'https://api.utmify.com.br/api-credentials/orders'
                && ($body['isTest'] ?? false) === true
                && ($body['platform'] ?? '') === config('getfy.app_name', 'Getfy');
        });
    }

    public function test_test_endpoint_returns_422_without_api_key(): void
    {
        $user = $this->createInfoprodutor();

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMIFY sem chave',
            'api_key' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('integrations.utmify.test', $integration))
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        Http::assertNothingSent();
    }

    public function test_order_id_stays_on_platform_id_when_gateway_id_changes(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'completed',
            'amount' => 80,
            'email' => 'buyer@example.com',
            'gateway' => 'cajupay',
            'gateway_id' => 'pay_replaced_after_checkout',
            'payment_method' => 'card',
        ]);
        $order->id = 321;
        $order->created_at = now();
        $order->updated_at = now();

        $payload = (new UtmifyService)->buildPayload($order, 'paid', []);

        $this->assertSame('321', $payload['orderId']);
    }

    public function test_customer_optional_fields_are_null_instead_of_empty_or_invalid(): void
    {
        $this->assertTrue(BrazilianDocuments::isValidCpf('11144477735'));

        $order = new Order([
            'tenant_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'pending',
            'amount' => 20,
            'email' => 'buyer@example.com',
            'phone' => '(11) 98888-7777',
            'cpf' => '00000000000',
            'customer_ip' => 'not-an-ip',
            'payment_method' => 'pix',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);
        $order->id = 654;
        $order->created_at = now();
        $order->updated_at = now();

        $customer = (new UtmifyService)->buildPayload($order, 'waiting_payment', [])['customer'];

        $this->assertSame('11988887777', $customer['phone']);
        $this->assertNull($customer['document']);
        $this->assertNull($customer['ip']);
        $this->assertSame('Cliente', $customer['name']);
        $this->assertSame('buyer@example.com', $customer['email']);
    }

    public function test_payload_includes_order_bump_as_extra_product_and_total(): void
    {
        $main = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Produto principal', 'price' => 80]);
        $bump = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Order bump', 'price' => 20]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 100,
            'email' => 'bump@example.com',
            'phone' => '11988887777',
            'cpf' => '11144477735',
            'customer_ip' => '203.0.113.10',
            'gateway_id' => 'gw-should-not-be-order-id',
            'payment_method' => 'pix',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'utm_source' => 'FB',
                'utm_campaign' => 'CAMPANHA|123',
            ],
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 80,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bump->id,
            'amount' => 20,
            'position' => 1,
        ]);

        $payload = (new UtmifyService)->buildPayload($order->fresh(), 'paid', []);

        $this->assertSame((string) $order->id, $payload['orderId']);
        $this->assertSame('pix', $payload['paymentMethod']);
        $this->assertCount(2, $payload['products']);
        $this->assertSame('Produto principal', $payload['products'][0]['name']);
        $this->assertSame(8000, $payload['products'][0]['priceInCents']);
        $this->assertSame('Order bump', $payload['products'][1]['name']);
        $this->assertSame((string) $bump->id, $payload['products'][1]['id']);
        $this->assertSame(2000, $payload['products'][1]['priceInCents']);
        $this->assertSame(10000, $payload['commission']['totalPriceInCents']);
        $this->assertSame(10000, $payload['commission']['userCommissionInCents']);
        $this->assertSame('FB', $payload['trackingParameters']['utm_source']);
        $this->assertSame('11144477735', $payload['customer']['document']);
        $this->assertSame('203.0.113.10', $payload['customer']['ip']);
    }

    public function test_upsell_tracking_is_inherited_without_copying_sent_flags(): void
    {
        $parent = new Order([
            'metadata' => [
                'checkout_session_token' => 'sess-parent',
                'utm_source' => 'FB',
                'utm_campaign' => 'CAMPANHA_2|99',
                'utm_medium' => 'CONJUNTO',
                'checkout_payment_method' => 'pix',
                'utmify_paid_sent_at' => now()->toIso8601String(),
            ],
        ]);

        $inherited = (new UtmifyService)->trackingMetadataFromOrder($parent);

        $this->assertSame([
            'checkout_session_token' => 'sess-parent',
            'utm_source' => 'FB',
            'utm_medium' => 'CONJUNTO',
            'utm_campaign' => 'CAMPANHA_2|99',
        ], $inherited);
        $this->assertArrayNotHasKey('utmify_paid_sent_at', $inherited);

        $upsell = new Order([
            'tenant_id' => 1,
            'product_id' => 'upsell-prod',
            'status' => 'pending',
            'amount' => 47,
            'email' => 'upsell@example.com',
            'payment_method' => 'pix',
            'metadata' => array_merge($inherited, [
                'checkout_payment_method' => 'pix',
            ]),
        ]);
        $upsell->id = 900;
        $upsell->created_at = now();
        $upsell->updated_at = now();

        $payload = (new UtmifyService)->buildPayload($upsell, 'waiting_payment', []);

        $this->assertSame('FB', $payload['trackingParameters']['utm_source']);
        $this->assertSame('CAMPANHA_2|99', $payload['trackingParameters']['utm_campaign']);
        $this->assertSame('CONJUNTO', $payload['trackingParameters']['utm_medium']);
        $this->assertNull($payload['approvedDate']);
    }

    public function test_tracking_values_are_capped_and_numeric_values_stay_strings(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'metadata' => [
                'utm_source' => 123456789,
                'utm_campaign' => str_repeat('C', 400),
                'utm_medium' => ['CONJUNTO', 'extra'],
            ],
        ]);
        $order->id = 777;
        $order->created_at = now();
        $order->updated_at = now();

        $tracking = (new UtmifyService)->buildPayload($order, 'waiting_payment', [])['trackingParameters'];

        $this->assertSame('123456789', $tracking['utm_source']);
        $this->assertSame(255, mb_strlen((string) $tracking['utm_campaign']));
        $this->assertSame('CONJUNTO', $tracking['utm_medium']);
        $this->assertNull($tracking['utm_content']);
        $this->assertNull($tracking['utm_term']);
        $this->assertNull($tracking['src']);
        $this->assertNull($tracking['sck']);
    }

    public function test_request_json_keeps_null_tracking_keys(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        $order = new Order([
            'tenant_id' => 1,
            'product_id' => 'prod-1',
            'status' => 'paid',
            'amount' => 10,
            'email' => 'buyer@example.com',
        ]);
        $order->id = 778;
        $order->created_at = now();
        $order->updated_at = now();

        (new UtmifyService)->sendOrder($order, 'paid', 'token-test');

        Http::assertSent(function ($request) {
            $raw = $request->body();

            return $request->url() === 'https://api.utmify.com.br/api-credentials/orders'
                && str_contains($raw, '"utm_source":null')
                && str_contains($raw, '"utm_medium":null')
                && str_contains($raw, '"utm_campaign":null')
                && str_contains($raw, '"utm_content":null')
                && str_contains($raw, '"utm_term":null')
                && str_contains($raw, '"src":null')
                && str_contains($raw, '"sck":null');
        });
    }
}
