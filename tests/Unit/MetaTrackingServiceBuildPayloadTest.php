<?php

namespace Tests\Unit;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaTrackingServiceBuildPayloadTest extends TestCase
{
    public function test_send_purchase_uses_event_id_and_meta_tokens(): void
    {
        Http::fake([
            'graph.facebook.com/*/events' => Http::response(['events_received' => 1], 200),
        ]);

        $order = new Order([
            'tenant_id' => 1,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'customer_ip' => '127.0.0.1',
            'metadata' => [
                'fbp' => 'fb.1.1234567890.1111111111',
                'fbc' => 'fb.1.1234567890.AbCdEfGhIj',
                'user_agent' => 'UnitTest UA',
                'event_source_url' => 'https://example.test/c/produto?fbclid=abc',
            ],
        ]);
        $order->id = 999;
        $order->created_at = now();
        $order->updated_at = now();

        $order->setRelation('product', new \App\Models\Product([
            'tenant_id' => 1,
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '123', 'access_token' => 'tok_abc'],
                    ],
                ],
            ],
        ]));
        $order->setRelation('checkoutSession', null);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect());

        $svc = app(MetaTrackingService::class);
        $context = app(\App\Services\Meta\MetaEventContextResolver::class)->forOrder($order);
        $payload = $svc->buildPayload('Purchase', 'order:999', $context);

        $this->assertSame('Purchase', $payload['data'][0]['event_name'] ?? null);
        $this->assertSame('order:999', $payload['data'][0]['event_id'] ?? null);
        $this->assertSame('https://example.test/c/produto?fbclid=abc', $payload['data'][0]['event_source_url'] ?? null);
        $ud = $payload['data'][0]['user_data'] ?? [];
        $this->assertSame('fb.1.1234567890.1111111111', $ud['fbp'] ?? null);
        $this->assertSame('fb.1.1234567890.AbCdEfGhIj', $ud['fbc'] ?? null);
        $this->assertSame('999', $payload['data'][0]['custom_data']['order_id'] ?? null);
        $this->assertSame('new_customer_to_business', $payload['data'][0]['customer_segmentation'] ?? null);
        $this->assertArrayNotHasKey('999', array_flip($payload['data'][0]['custom_data']['content_ids'] ?? []));
    }

    public function test_purchase_payload_uses_product_slug_phone_and_cpf(): void
    {
        $order = new Order([
            'tenant_id' => 1,
            'status' => 'completed',
            'amount' => 97,
            'email' => 'Buyer@Example.com',
            'phone' => '(11) 98888-7777',
            'cpf' => '390.533.447-05',
            'shipping_address' => [
                'city' => 'São Paulo',
                'state' => 'São Paulo',
                'cep' => '01310-100',
            ],
        ]);
        $order->id = 42;
        $order->created_at = now();
        $order->updated_at = now();
        $order->setRelation('product', new \App\Models\Product([
            'name' => 'Curso',
            'checkout_slug' => 'curso-x',
        ]));
        $order->setRelation('productOffer', null);
        $order->setRelation('subscriptionPlan', null);
        $order->setRelation('checkoutSession', null);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect());

        $hasher = new \App\Services\Meta\MetaUserDataHasher;
        $context = app(\App\Services\Meta\MetaEventContextResolver::class)->forOrder($order);
        $payload = app(MetaTrackingService::class)->buildPayload('Purchase', 'order:42', $context);
        $event = $payload['data'][0];
        $custom = $event['custom_data'];
        $user = $event['user_data'];

        $this->assertSame(['curso-x'], $custom['content_ids']);
        $this->assertSame('42', $custom['order_id']);
        $this->assertSame('curso-x', $custom['contents'][0]['id']);
        $this->assertSame(97.0, $custom['contents'][0]['item_price']);
        $this->assertSame([$hasher->hashPhone('5511988887777')], $user['ph']);
        $this->assertSame([$hasher->hashExternalId('1:buyer@example.com')], $user['external_id']);
        $this->assertNotContains($hasher->hashCpf('39053344705'), $user['external_id']);
        $this->assertStringNotContainsString('39053344705', json_encode($payload));
        $this->assertSame($hasher->hashCity('saopaulo'), $user['ct'][0]);
        $this->assertSame($hasher->hashState('sp'), $user['st'][0]);

        $refund = app(MetaTrackingService::class)->buildPayload('Refund', 'refund:42', $context);
        $this->assertSame('Refund', $refund['data'][0]['event_name']);
        $this->assertSame('refund:42', $refund['data'][0]['event_id']);
        $this->assertSame('Purchase', $refund['data'][0]['original_event_data']['event_name']);
        $this->assertSame('order:42', $refund['data'][0]['original_event_data']['event_id']);
        $this->assertSame('42', $refund['data'][0]['original_event_data']['order_id']);
    }

    public function test_queue_session_event_persists_event_source_url_for_later_send(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'checkout_slug' => 'meta-url-persist',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '555666777', 'access_token' => 'tok'],
                    ],
                ],
            ],
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'meta-url-persist',
            'session_token' => 'sess-url-persist',
            'step' => CheckoutSession::STEP_VISIT,
            'customer_ip' => '127.0.0.1',
        ]);

        $svc = app(MetaTrackingService::class);
        $svc->queueSessionEvent($session, 'PageView', 'pv:sess-url-persist', [
            'event_source_url' => 'https://example.test/c/meta-url-persist?fbclid=xyz',
            'user_agent' => 'PHPUnit UA',
        ]);

        $session->refresh();
        $this->assertSame('https://example.test/c/meta-url-persist?fbclid=xyz', $session->meta_page_url);
        $this->assertSame('PHPUnit UA', $session->meta_user_agent);

        // Later mirrors (thank-you page) must not overwrite the checkout landing URL.
        $svc->persistSessionAttribution($session, [
            'event_source_url' => 'https://example.test/obrigado',
        ]);
        $session->refresh();
        $this->assertSame('https://example.test/c/meta-url-persist?fbclid=xyz', $session->meta_page_url);

        $context = app(\App\Services\Meta\MetaEventContextResolver::class)->forCheckoutSession($session);
        $payload = $svc->buildPayload('PageView', 'pv:sess-url-persist', $context);
        $this->assertSame(
            'https://example.test/c/meta-url-persist?fbclid=xyz',
            $payload['data'][0]['event_source_url'] ?? null
        );
    }

    public function test_merge_session_attribution_copies_event_source_url(): void
    {
        $session = new CheckoutSession([
            'meta_fbp' => 'fb.1.1',
            'meta_fbc' => 'fb.1.2',
            'meta_user_agent' => 'UA',
            'meta_page_url' => 'https://example.test/c/x',
        ]);

        $merged = app(MetaTrackingService::class)->mergeSessionAttributionIntoOrder($session, []);

        $this->assertSame('https://example.test/c/x', $merged['event_source_url'] ?? null);
        $this->assertSame('fb.1.1', $merged['fbp'] ?? null);
        $this->assertSame('fb.1.2', $merged['fbc'] ?? null);

        $kept = app(MetaTrackingService::class)->mergeSessionAttributionIntoOrder($session, [
            'fbc' => 'fb.1.cookie.click',
        ]);
        $this->assertSame('fb.1.cookie.click', $kept['fbc'] ?? null);

        $blank = app(MetaTrackingService::class)->mergeSessionAttributionIntoOrder($session, [
            'fbc' => '',
        ]);
        $this->assertSame('fb.1.2', $blank['fbc'] ?? null);
    }

    public function test_persist_does_not_replace_existing_fbc_with_empty_or_later_value(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'meta-fbc-keep']);
        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'meta-fbc-keep',
            'session_token' => 'sess-fbc-keep',
            'step' => CheckoutSession::STEP_VISIT,
            'meta_fbc' => 'fb.1.111.CLICK',
            'meta_fbp' => 'fb.1.111.browser',
        ]);

        $svc = app(MetaTrackingService::class);
        $svc->persistSessionAttribution($session, [
            'fbc' => '',
            'fbp' => 'fb.1.999.other',
        ]);
        $session->refresh();
        $this->assertSame('fb.1.111.CLICK', $session->meta_fbc);
        $this->assertSame('fb.1.111.browser', $session->meta_fbp);
    }
}
