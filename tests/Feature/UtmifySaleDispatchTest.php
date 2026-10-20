<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Events\OrderRefunded;
use App\Events\PixGenerated;
use App\Jobs\UtmifySendOrderJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\UtmifyIntegration;
use App\Models\User;
use App\Support\IntegrationJobDispatch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UtmifySaleDispatchTest extends TestCase
{
    public function test_order_completed_sends_paid_payload_to_utmify_api(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy e2e',
            'api_key' => 'e2e-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99.90,
            'email' => 'e2e@example.com',
            'metadata' => [
                'utm_source' => 'facebook',
                'utm_campaign' => 'black-friday',
            ],
        ]);

        event(new OrderCompleted($order));

        Http::assertSent(function ($request) use ($order) {
            if ($request->url() !== 'https://api.utmify.com.br/api-credentials/orders') {
                return false;
            }

            $body = $request->data();
            $tracking = $body['trackingParameters'] ?? [];

            foreach (\App\Models\CheckoutSession::TRACKING_FIELD_KEYS as $key) {
                if (! array_key_exists($key, $tracking)) {
                    return false;
                }
            }

            return $request->hasHeader('x-api-token', 'e2e-api-key')
                && ($body['status'] ?? '') === 'paid'
                && ($body['orderId'] ?? '') === (string) $order->id
                && ($tracking['utm_source'] ?? '') === 'facebook'
                && ($tracking['utm_campaign'] ?? '') === 'black-friday'
                && $tracking['utm_medium'] === null
                && $tracking['utm_content'] === null
                && $tracking['utm_term'] === null
                && $tracking['sck'] === null
                && $tracking['src'] === null;
        });

        $order->refresh();
        $this->assertNotEmpty($order->metadata['utmify_paid_sent_at'] ?? null);
    }

    public function test_waiting_payment_skipped_when_order_already_paid(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy regression',
            'api_key' => 'regression-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'regression@example.com',
            'metadata' => ['utmify_paid_sent_at' => now()->subMinutes(5)->toIso8601String()],
        ]);

        $job = new UtmifySendOrderJob($integration->id, $order->id, 'waiting_payment');
        $job->handle(app(\App\Services\UtmifyService::class));

        Http::assertNothingSent();
    }

    public function test_product_filter_prevents_job_dispatch(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $productA = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Produto A']);
        $productB = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Produto B']);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy filtered',
            'api_key' => 'filter-api-key',
            'is_active' => true,
        ]);
        $integration->products()->sync([$productB->id]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $productA->id,
            'status' => 'completed',
            'amount' => 30,
            'email' => 'filter@example.com',
        ]);

        event(new OrderCompleted($order));

        Queue::assertNotPushed(UtmifySendOrderJob::class);
    }

    public function test_pix_generated_dispatches_waiting_payment_job(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy pix',
            'api_key' => 'pix-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 40,
            'email' => 'pix@example.com',
            'payment_method' => 'pix',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        event(new PixGenerated($order, ['qr_code' => 'test']));

        Queue::assertPushed(UtmifySendOrderJob::class, function (UtmifySendOrderJob $job) use ($order) {
            return $job->orderId === $order->id
                && $job->utmifyStatus === 'waiting_payment'
                && $job->queue === 'utmify-tracking';
        });
    }

    public function test_card_order_pending_dispatches_waiting_payment_for_cajupay_style_checkout(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy card',
            'api_key' => 'card-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 80,
            'email' => 'card@example.com',
            'payment_method' => 'card',
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);

        event(new \App\Events\OrderPending($order));

        Queue::assertPushed(UtmifySendOrderJob::class, function (UtmifySendOrderJob $job) use ($order) {
            return $job->orderId === $order->id && $job->utmifyStatus === 'waiting_payment';
        });
    }

    public function test_pix_order_pending_does_not_dispatch_waiting_payment(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy pix pending',
            'api_key' => 'pix-pending-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 40,
            'email' => 'pix-pending@example.com',
            'payment_method' => 'pix',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        event(new \App\Events\OrderPending($order));

        Queue::assertNotPushed(UtmifySendOrderJob::class);
    }

    public function test_failed_job_records_error_metadata(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy fail',
            'api_key' => 'fail-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 20,
            'email' => 'fail@example.com',
        ]);

        $job = new UtmifySendOrderJob(
            $integration->id,
            $order->id,
            'paid',
            now()->utc()->format('Y-m-d H:i:s')
        );
        $job->failed(new \RuntimeException('UTMIFY API error: 401 Unauthorized'));

        $order->refresh();
        $this->assertNotEmpty($order->metadata['utmify_failed_at'] ?? null);
        $this->assertStringContainsString('401', $order->metadata['utmify_last_error'] ?? '');
    }

    public function test_order_refunded_sends_original_approved_date(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy refund',
            'api_key' => 'refund-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'refunded',
            'amount' => 75,
            'email' => 'refund@example.com',
            'metadata' => [
                'utmify_approved_at' => '2024-07-15 13:30:14',
            ],
        ]);

        event(new OrderRefunded($order));

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();

            return $request->url() === 'https://api.utmify.com.br/api-credentials/orders'
                && ($body['status'] ?? '') === 'refunded'
                && ($body['orderId'] ?? '') === (string) $order->id
                && ($body['approvedDate'] ?? null) === '2024-07-15 13:30:14'
                && ! empty($body['refundedAt']);
        });
    }

    public function test_queue_worker_does_not_defer_utmify_job_after_response(): void
    {
        $this->assertTrue(app()->runningInConsole());
        $this->assertFalse(IntegrationJobDispatch::shouldDeferAfterResponse());

        config(['queue.default' => 'redis']);
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy immediate',
            'api_key' => 'immediate-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 15,
            'email' => 'immediate@example.com',
        ]);

        event(new OrderCompleted($order));

        Queue::assertPushed(UtmifySendOrderJob::class, function (UtmifySendOrderJob $job) use ($order) {
            return $job->orderId === $order->id && $job->utmifyStatus === 'paid';
        });
    }
}
