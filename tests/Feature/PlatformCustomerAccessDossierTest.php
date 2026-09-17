<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberStudentActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformCustomerAccessDossierTest extends TestCase
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

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seller(): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'name' => 'Seller Acesso',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    /**
     * @return array{seller: User, product: Product, customer: User, order: Order}
     */
    private function customerWithMemberAccess(): array
    {
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso Dossiê Admin',
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 97,
        ]);

        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
            'name' => 'Aluno Reclamação',
            'email' => 'aluno.dossie.admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97,
            'email' => $customer->email,
            'payment_method' => 'pix',
            'gateway' => 'demo',
        ]);

        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        MemberStudentActivityLog::query()->create([
            'user_id' => $customer->id,
            'product_id' => (string) $product->id,
            'event' => MemberStudentActivityLog::EVENT_LOGIN,
            'ip' => '203.0.113.88',
        ]);

        return compact('seller', 'product', 'customer', 'order');
    }

    public function test_admin_show_includes_access_products_and_order_dossier_flag(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer, 'product' => $product] = $this->customerWithMemberAccess();

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.show', $customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Customers/Show')
                ->has('access_products', 1)
                ->where('access_products.0.id', (string) $product->id)
                ->where('access_products.0.name', 'Curso Dossiê Admin')
                ->where('orders.data.0.has_access_dossier', true)
                ->where('orders.data.0.product_id', (string) $product->id)
            );
    }

    public function test_admin_can_open_customer_access_dossier_with_ip_timeline(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer, 'product' => $product] = $this->customerWithMemberAccess();

        $response = $this->actingAs($admin)->getJson(route('plataforma.clientes.acessos.dossie', [
            'user' => $customer->id,
            'produto' => $product->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('product.id', $product->id);
        $response->assertJsonPath('student.email', $customer->email);
        $events = $response->json('events');
        $this->assertIsArray($events);
        $this->assertTrue(collect($events)->contains(
            fn ($event) => ($event['event'] ?? '') === MemberStudentActivityLog::EVENT_LOGIN
                && ($event['ip'] ?? '') === '203.0.113.88'
        ));
        $this->assertTrue(collect($events)->contains(
            fn ($event) => ($event['event'] ?? '') === MemberStudentActivityLog::EVENT_ENROLLED
        ));

        if (Schema::hasTable('platform_audit_logs')) {
            $this->assertDatabaseHas('platform_audit_logs', [
                'action' => 'platform.customer.access_dossier.viewed',
            ]);
        }
    }

    public function test_admin_can_export_customer_access_dossier_csv_and_pdf(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer, 'product' => $product] = $this->customerWithMemberAccess();

        $csv = $this->actingAs($admin)->get(route('plataforma.clientes.acessos.dossie.export', [
            'user' => $customer->id,
            'produto' => $product->id,
        ]));
        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $csv->streamedContent();
        $this->assertStringContainsString($customer->email, $body);
        $this->assertStringContainsString('Data;Evento;IP', $body);
        $this->assertStringContainsString('203.0.113.88', $body);

        $pdf = $this->actingAs($admin)->get(route('plataforma.clientes.acessos.dossie.export-pdf', [
            'user' => $customer->id,
            'produto' => $product->id,
        ]));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_admin_can_open_dossier_after_enrollment_revoked_if_order_exists(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer, 'product' => $product] = $this->customerWithMemberAccess();

        DB::table('product_user')
            ->where('user_id', $customer->id)
            ->where('product_id', $product->id)
            ->delete();

        $this->actingAs($admin)
            ->getJson(route('plataforma.clientes.acessos.dossie', [
                'user' => $customer->id,
                'produto' => $product->id,
            ]))
            ->assertOk()
            ->assertJsonPath('product.id', $product->id);
    }

    public function test_admin_cannot_open_unrelated_product_dossier(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer] = $this->customerWithMemberAccess();
        $otherProduct = $this->createTestProduct(['name' => 'Outro produto']);

        $this->actingAs($admin)
            ->getJson(route('plataforma.clientes.acessos.dossie', [
                'user' => $customer->id,
                'produto' => $otherProduct->id,
            ]))
            ->assertNotFound();
    }

    public function test_seller_cannot_open_platform_customer_dossier(): void
    {
        $seller = $this->seller();
        ['customer' => $customer, 'product' => $product] = $this->customerWithMemberAccess();

        $this->actingAs($seller)
            ->getJson(route('plataforma.clientes.acessos.dossie', [
                'user' => $customer->id,
                'produto' => $product->id,
            ]))
            ->assertForbidden();
    }
}
