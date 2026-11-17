<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class SellerMedDisputesTest extends TestCase
{
    public function test_seller_sees_product_and_api_pix_disputes_but_not_med_zero(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $tenantId = (int) $seller->id;

        $product = $this->createTestProduct(['tenant_id' => $tenantId]);
        $checkoutOrder = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'disputed',
            'amount' => 50,
            'email' => 's@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);

        $apiApp = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => 'API',
            'slug' => ApiApplication::generateUniqueSlug($tenantId, 'API'),
            'api_key_hash' => hash('sha256', 'k'),
            'public_key' => ApiApplication::generatePublicKey(),
            'secret_key_hash' => hash('sha256', 's'),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => [],
        ]);
        $apiOrder = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'api_application_id' => $apiApp->id,
            'status' => 'disputed',
            'amount' => 40,
            'email' => 'api@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'metadata' => ['source' => 'api'],
        ]);

        $productDispute = MedDispute::create([
            'order_id' => $checkoutOrder->id,
            'tenant_id' => $tenantId,
            'responsible_party' => MedDispute::PARTY_PLATFORM,
            'cajupay_dispute_id' => 'product-d-1',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 5000,
            'opened_at' => now(),
        ]);

        $apiDispute = MedDispute::create([
            'order_id' => $apiOrder->id,
            'tenant_id' => $tenantId,
            'responsible_party' => MedDispute::PARTY_TENANT,
            'cajupay_dispute_id' => 'api-d-1',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 4000,
            'opened_at' => now(),
        ]);

        $hidden = MedDispute::create([
            'order_id' => $apiOrder->id,
            'tenant_id' => $tenantId,
            'responsible_party' => MedDispute::PARTY_PLATFORM,
            'cajupay_dispute_id' => 'med-zero-d-1',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 4000,
            'opened_at' => now(),
        ]);

        $this->actingAs($seller)
            ->get(route('disputas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Disputas/Index')
                ->has('disputes', 2)
                ->where('disputes.0.id', $apiDispute->id)
                ->where('disputes.1.id', $productDispute->id)
            );

        $this->actingAs($seller)
            ->get(route('disputas.show', $productDispute))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('dispute.managed_by_platform', true)
                ->where('dispute.balance_held', true)
                ->where('dispute.origin_label', 'Infoproduto')
            );

        $this->actingAs($seller)
            ->get(route('disputas.show', $apiDispute))
            ->assertOk();

        $this->actingAs($seller)
            ->get(route('disputas.show', $hidden))
            ->assertNotFound();

        $this->actingAs($seller)
            ->post(route('disputas.defense', $productDispute), ['text' => 'Defesa que a plataforma conduz.'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
