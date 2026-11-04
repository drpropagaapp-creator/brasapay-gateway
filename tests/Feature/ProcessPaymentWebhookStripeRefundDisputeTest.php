<?php

namespace Tests\Feature;

use App\Gateways\Stripe\StripeDriver;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class ProcessPaymentWebhookStripeRefundDisputeTest extends TestCase
{
    private function makeCompletedStripeOrder(string $piId = 'pi_test_rd'): Order
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'Stripe product']);

        $cred = new GatewayCredential(['tenant_id' => 1, 'gateway_slug' => 'stripe', 'is_connected' => true]);
        $cred->setEncryptedCredentials(['secret_key' => 'sk_test_fake']);
        $cred->save();

        return Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer-stripe@example.com',
            'gateway' => 'stripe',
            'gateway_id' => $piId,
        ]);
    }

    public function test_full_refund_confirmed_by_stripe_marks_order_refunded(): void
    {
        $this->mock(StripeDriver::class, fn ($m) => $m->shouldReceive('getTransactionStatus')->once()->andReturn('cancelled'));
        $order = $this->makeCompletedStripeOrder();

        ProcessPaymentWebhook::dispatchSync('stripe', 'pi_test_rd', 'payment.refunded', 'refunded', []);

        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_refund_event_not_confirmed_by_stripe_is_ignored(): void
    {
        $this->mock(StripeDriver::class, fn ($m) => $m->shouldReceive('getTransactionStatus')->once()->andReturn('paid'));
        $order = $this->makeCompletedStripeOrder();

        ProcessPaymentWebhook::dispatchSync('stripe', 'pi_test_rd', 'payment.refunded', 'refunded', []);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_dispute_created_then_won_restores_order(): void
    {
        $order = $this->makeCompletedStripeOrder();

        ProcessPaymentWebhook::dispatchSync('stripe', 'pi_test_rd', 'payment.disputed', 'disputed', []);
        $this->assertSame('disputed', $order->fresh()->status);

        ProcessPaymentWebhook::dispatchSync('stripe', 'pi_test_rd', 'payment.dispute_closed', 'won', []);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_dispute_lost_refunds_order(): void
    {
        $order = $this->makeCompletedStripeOrder();

        ProcessPaymentWebhook::dispatchSync('stripe', 'pi_test_rd', 'payment.disputed', 'disputed', []);
        ProcessPaymentWebhook::dispatchSync('stripe', 'pi_test_rd', 'payment.dispute_closed', 'lost', []);

        $this->assertSame('refunded', $order->fresh()->status);
    }
}
