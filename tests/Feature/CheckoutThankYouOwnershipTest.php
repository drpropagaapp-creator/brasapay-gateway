<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\CheckoutOrderOwnership;
use Tests\TestCase;

class CheckoutThankYouOwnershipTest extends TestCase
{
    private function makeOrder(User $buyer): Order
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        return Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => 'comprador@example.com',
            'phone' => '11999998888',
            'payment_method' => 'pix',
            'metadata' => [],
        ]);
    }

    public function test_stranger_guessing_order_id_does_not_receive_buyer_pii(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'name' => 'Fulano Comprador']);
        $order = $this->makeOrder($buyer);

        $this->get(route('checkout.thank-you', ['order_id' => $order->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customer_email', null)
                ->where('customer_phone', null)
                ->where('customer_name', null));
    }

    public function test_browser_that_created_the_order_receives_buyer_pii(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'name' => 'Fulano Comprador']);
        $order = $this->makeOrder($buyer);

        $this->withSession([CheckoutOrderOwnership::SESSION_KEY => [$order->id]])
            ->get(route('checkout.thank-you', ['order_id' => $order->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customer_email', 'comprador@example.com')
                ->where('customer_phone', '11999998888')
                ->where('customer_name', 'Fulano Comprador'));
    }

    public function test_logged_in_owner_receives_buyer_pii(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'name' => 'Fulano Comprador']);
        $order = $this->makeOrder($buyer);

        $this->actingAs($buyer)
            ->get(route('checkout.thank-you', ['order_id' => $order->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('customer_email', 'comprador@example.com'));
    }

    public function test_order_created_during_a_web_request_is_remembered_in_session(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);

        $this->startSession();
        request()->setLaravelSession($this->app['session.store']);
        $order = $this->makeOrder($buyer);

        $this->assertContains($order->id, session(CheckoutOrderOwnership::SESSION_KEY, []));
    }
}
