<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class CustomerImpersonationTest extends TestCase
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

    public function test_admin_opens_customer_panel_read_only_and_can_return(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso do Aluno',
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 49,
        ]);

        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
            'name' => 'Aluno Teste',
            'email' => 'aluno.impersonar@example.com',
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49,
            'email' => $customer->email,
            'payment_method' => 'pix',
            'gateway' => 'demo',
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.clientes.impersonate', $customer))
            ->assertRedirect('/painel-cliente');

        $this->assertAuthenticatedAs($admin);

        $this->get('/painel-cliente')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Cliente/Index')
                ->where('customer_impersonation.active', true)
                ->where('customer_impersonation.email', 'aluno.impersonar@example.com')
                ->where('purchases.0.product_name', 'Curso do Aluno'));

        $this->get(route('plataforma.clientes.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Customers/Index')
                ->where('customer_impersonation', null));

        $this->from('/painel-cliente')
            ->post(route('painel-cliente.refund'), [
                'order_id' => $order->id,
                'reason' => 'Não deveria gravar',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('refund_requests', [
            'order_id' => $order->id,
        ]);

        $this->post(route('customer-impersonation.leave'))
            ->assertRedirect(route('plataforma.clientes.show', $customer));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_seller_cannot_impersonate_a_customer(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);
        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $this->createTestProduct(['tenant_id' => $seller->id])->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $customer->email,
            'payment_method' => 'pix',
            'gateway' => 'demo',
        ]);

        $this->actingAs($seller)
            ->post(route('plataforma.clientes.impersonate', $customer))
            ->assertForbidden();
    }

    public function test_impersonated_seller_customer_cannot_open_the_sales_panel(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'name' => 'Seller Comprador',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'price' => 20]);
        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 20,
            'email' => $seller->email,
            'payment_method' => 'pix',
            'gateway' => 'demo',
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.clientes.impersonate', $seller))
            ->assertRedirect('/painel-cliente');

        $this->get('/dashboard')->assertRedirect('/painel-cliente');
    }
}
