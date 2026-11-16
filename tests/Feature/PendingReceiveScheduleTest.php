<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PendingReceiveScheduleTest extends TestCase
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

    public function test_refunded_card_orders_are_hidden_from_pending_receive_dates(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('orders')) {
            $this->markTestSkipped('wallet tables');
        }

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $open = $this->cardOrder($seller, 'completed');
        $refunded = $this->cardOrder($seller, 'refunded');
        $pendingRefund = $this->cardOrder($seller, 'refund_pending');

        $this->pendingCredit($seller, $open, '2026-12-20', 50.00);
        $this->pendingCredit($seller, $refunded, '2026-12-05', 80.00);
        $this->pendingCredit($seller, $pendingRefund, '2026-12-12', 30.00);

        $response = $this->actingAs($seller)->get(route('financeiro.seller.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Financeiro/Index')
            ->where('pending_receive_by_date', [
                [
                    'date' => '2026-12-20',
                    'amount' => 50,
                    'count' => 1,
                ],
            ])
        );
    }

    private function cardOrder(User $seller, string $status): Order
    {
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        return Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => $status,
            'amount' => 100,
            'email' => $buyer->email,
            'payment_method' => 'card',
        ]);
    }

    private function pendingCredit(User $seller, Order $order, string $date, float $net): void
    {
        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'order_id' => $order->id,
            'bucket' => 'card',
            'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            'amount_gross' => $net,
            'amount_fee' => 0,
            'amount_net' => $net,
            'meta' => [
                'clears_at' => $date.'T12:00:00-03:00',
                'portion' => 'main',
            ],
        ]);
    }
}
