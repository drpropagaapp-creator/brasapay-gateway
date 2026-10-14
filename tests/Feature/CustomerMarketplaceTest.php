<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\SaleOrigin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class CustomerMarketplaceTest extends TestCase
{
    public function test_marketplace_lists_opted_in_products_and_hides_owned_ones(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'name' => 'Loja Aurora',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => $seller->id,
        ]);

        $visible = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso visivel',
            'category' => 'educacao_e_cursos',
            'checkout_slug' => 'mktvis1',
            'price' => 49.9,
            'currency' => 'BRL',
            'show_in_marketplace' => true,
            'is_active' => true,
        ]);

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso oculto',
            'checkout_slug' => 'mktoculto',
            'show_in_marketplace' => false,
            'is_active' => true,
        ]);

        $owned = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso ja comprado',
            'checkout_slug' => 'mktowned',
            'show_in_marketplace' => true,
            'is_active' => true,
        ]);

        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $owned->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $buyer->email,
            'gateway' => 'pix',
        ]);

        $inactive = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso inativo',
            'checkout_slug' => 'mktinativo',
            'show_in_marketplace' => true,
            'is_active' => false,
        ]);

        $this->actingAs($buyer)
            ->get(route('painel-cliente.marketplace'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Cliente/Marketplace')
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Curso visivel')
                ->where('products.data.0.seller_name', 'Loja Aurora')
                ->where('products.data.0.checkout_url', url('/c/mktvis1').'?origem=marketplace'));

        $this->actingAs($buyer)
            ->get(route('painel-cliente.marketplace', ['q' => 'nenhum', 'category' => 'educacao_e_cursos']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products.data', 0));

        $this->assertNotSame($visible->id, $owned->id);
        $this->assertFalse((bool) $inactive->is_active);
    }

    public function test_seller_can_opt_product_into_marketplace(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'price' => 10,
            'currency' => 'BRL',
            'category' => 'educacao_e_cursos',
            'show_in_marketplace' => false,
        ]);

        $this->actingAs($seller)->put(route('produtos.update', $product->id), [
            'name' => $product->name,
            'description' => $product->description,
            'category' => 'educacao_e_cursos',
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'price' => 10,
            'currency' => 'BRL',
            'is_active' => true,
            'show_in_marketplace' => true,
        ])->assertRedirect();

        $this->assertTrue((bool) $product->fresh()->show_in_marketplace);
    }

    public function test_checkout_from_marketplace_marks_the_sale_origin(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Produto marketplace',
            'price' => 19.9,
            'checkout_slug' => 'mktchk1',
            'checkout_config' => [
                'customer_fields' => [
                    'name' => false,
                    'cpf' => false,
                    'phone' => false,
                    'coupon' => false,
                ],
            ],
        ]);

        $this->get('/c/'.$product->checkout_slug.'?origem=marketplace')->assertOk();

        $session = CheckoutSession::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($session);
        $this->assertTrue((bool) $session->from_marketplace);

        $order = new Order;
        $order->metadata = CheckoutSession::applyMarketplaceOrigin([], $session);

        $this->assertSame(SaleOrigin::MARKETPLACE, SaleOrigin::resolveForOrder($order));
    }
}
