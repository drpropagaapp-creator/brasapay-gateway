<?php

namespace Tests\Feature;

use App\Gateways\Stripe\StripeDriver;
use App\Jobs\ChargeDueSubscriptionsWithSavedCardJob;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\SavedPaymentMethod;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Mockery;
use Tests\TestCase;

/**
 * Renovação automática no cartão (07:00): usa o cliente Stripe guardado e cobra off_session.
 * Cartão guardado sem cliente (antes da correção) não é tentado — a Stripe recusaria.
 */
class SubscriptionSavedCardRenewalTest extends TestCase
{
    private function dueSubscription(?string $customerId): Subscription
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED, 'account_status' => 'approved']);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'type' => Product::TYPE_AREA_MEMBROS, 'billing_type' => Product::BILLING_SUBSCRIPTION]);
        $plan = SubscriptionPlan::create(['product_id' => $product->id, 'name' => 'Mensal', 'price' => 97, 'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY, 'checkout_slug' => 'p'.uniqid(), 'position' => 1]);
        $card = SavedPaymentMethod::create([
            'tenant_id' => $seller->id, 'user_id' => $buyer->id, 'gateway' => 'stripe',
            'gateway_payment_method_id' => 'pm_sub', 'gateway_customer_id' => $customerId, 'last_four' => '4242', 'brand' => 'visa', 'type' => 'card',
        ]);

        Setting::set('gateway_order', ['pix' => [], 'card' => ['stripe'], 'boleto' => [], 'pix_auto' => []], null);
        $cred = new GatewayCredential(['tenant_id' => null, 'gateway_slug' => 'stripe', 'is_connected' => true, 'is_enabled' => true]);
        $cred->setEncryptedCredentials(['secret_key' => 'sk_test_x', 'publishable_key' => 'pk_test_x']);
        $cred->save();

        return Subscription::create([
            'tenant_id' => $seller->id, 'user_id' => $buyer->id, 'product_id' => $product->id, 'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE, 'current_period_start' => now()->subMonth()->startOfDay(), 'current_period_end' => now()->startOfDay(),
            'saved_payment_method_id' => $card->id,
        ]);
    }

    public function test_renovacao_cobra_com_cliente_stripe_e_off_session(): void
    {
        $this->dueSubscription('cus_sub');
        $captured = null;
        $driver = Mockery::mock(StripeDriver::class);
        $driver->shouldReceive('createCardPayment')->once()
            ->andReturnUsing(function ($credentials, $amount, $consumer, $externalId, array $card) use (&$captured) {
                $captured = $card;

                return ['transaction_id' => 'pi_renew', 'status' => 'paid'];
            });
        $this->app->instance(StripeDriver::class, $driver);

        app()->call([new ChargeDueSubscriptionsWithSavedCardJob, 'handle']);

        $this->assertSame('pm_sub', $captured['payment_token']);
        $this->assertSame('cus_sub', $captured['customer_id']);
        $this->assertTrue($captured['off_session']);
        $this->assertSame('brl', $captured['currency']);
        $this->assertSame(1, Order::query()->where('is_renewal', true)->where('status', 'completed')->count());
    }

    public function test_cartao_antigo_sem_cliente_nao_e_tentado(): void
    {
        $this->dueSubscription(null);
        $driver = Mockery::mock(StripeDriver::class);
        $driver->shouldNotReceive('createCardPayment');
        $this->app->instance(StripeDriver::class, $driver);

        app()->call([new ChargeDueSubscriptionsWithSavedCardJob, 'handle']);

        $this->assertSame(0, Order::count());
    }
}
