<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\UtmifySendOrderJob;
use App\Models\Order;
use App\Models\UtmifyIntegration;
use App\Models\UtmifyOrderDispatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UtmifyOrderCompletedTest extends TestCase
{
    public function test_order_completed_dispatches_utmify_paid_job(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy test',
            'api_key' => 'test-api-key',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'email' => 'buyer@example.com',
        ]);

        event(new OrderCompleted($order));

        Queue::assertPushed(UtmifySendOrderJob::class, function (UtmifySendOrderJob $job) use ($order) {
            return $job->orderId === $order->id && $job->utmifyStatus === 'paid';
        });
    }

    public function test_utmify_paid_job_is_idempotent_via_dispatch_row(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy idempotent',
            'api_key' => 'test-api-key-2',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'idempotent@example.com',
            'metadata' => ['utmify_paid_sent_at' => now()->subHour()->toIso8601String()],
        ]);

        UtmifyOrderDispatch::create([
            'tenant_id' => 1,
            'order_id' => $order->id,
            'utmify_integration_id' => $integration->id,
            'utmify_status' => 'paid',
            'dispatch_status' => UtmifyOrderDispatch::DISPATCH_SENT,
            'attempts' => 1,
            'sent_at' => now()->subHour(),
        ]);

        $job = new UtmifySendOrderJob(
            $integration->id,
            $order->id,
            'paid',
            now()->utc()->format('Y-m-d H:i:s')
        );
        $job->handle(app(\App\Services\UtmifyService::class));

        Http::assertNothingSent();
    }

    public function test_second_integration_sends_paid_even_when_order_metadata_flag_exists(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $first = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy A',
            'api_key' => 'key-a',
            'is_active' => true,
        ]);
        $second = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy B',
            'api_key' => 'key-b',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 60,
            'email' => 'multi@example.com',
            'metadata' => ['utmify_paid_sent_at' => now()->subMinutes(2)->toIso8601String()],
        ]);

        UtmifyOrderDispatch::create([
            'tenant_id' => 1,
            'order_id' => $order->id,
            'utmify_integration_id' => $first->id,
            'utmify_status' => 'paid',
            'dispatch_status' => UtmifyOrderDispatch::DISPATCH_SENT,
            'attempts' => 1,
            'sent_at' => now()->subMinutes(2),
        ]);

        $job = new UtmifySendOrderJob(
            $second->id,
            $order->id,
            'paid',
            now()->utc()->format('Y-m-d H:i:s')
        );
        $job->handle(app(\App\Services\UtmifyService::class));

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-token', 'key-b')
                && ($request->data()['status'] ?? '') === 'paid';
        });
    }

    public function test_utmify_paid_job_sets_metadata_after_success(): void
    {
        Http::fake([
            'api.utmify.com.br/*' => Http::response(['ok' => true], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMfy success',
            'api_key' => 'test-api-key-3',
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 75,
            'email' => 'paid@example.com',
        ]);

        $job = new UtmifySendOrderJob(
            $integration->id,
            $order->id,
            'paid',
            now()->utc()->format('Y-m-d H:i:s')
        );
        $job->handle(app(\App\Services\UtmifyService::class));

        $order->refresh();
        $this->assertNotEmpty($order->metadata['utmify_paid_sent_at'] ?? null);
        Http::assertSentCount(1);
    }
}
