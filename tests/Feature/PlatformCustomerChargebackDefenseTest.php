<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\MemberStudentActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\ChargebackDefensePdfService;
use App\Support\PlatformCompanySettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformCustomerChargebackDefenseTest extends TestCase
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
            'name' => 'Seller Contestação',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    private function configureCompany(): void
    {
        Setting::set(PlatformCompanySettings::KEY_LEGAL_NAME, 'JAS MARKETING E DESENVOLVIMENTO LTDA', null);
        Setting::set(PlatformCompanySettings::KEY_CNPJ, '11222333000181', null);
    }

    /**
     * @return array{seller: User, product: Product, customer: User, order: Order}
     */
    private function customerWithMemberAccess(array $orderOverrides = []): array
    {
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Vupt Transitions – Anual',
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 119.88,
        ]);

        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
            'name' => 'Gabriel Soares',
            'email' => 'ggrsoares.rec@example.com',
            'document' => '39053344705',
            'password' => Hash::make('password'),
        ]);

        $order = Order::create(array_merge([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 119.88,
            'email' => $customer->email,
            'cpf' => '39053344705',
            'payment_method' => 'card',
            'gateway' => 'cappta',
            'gateway_id' => 'c11521b5-6788-43b3-87da-f9af214da03b',
            'customer_ip' => '203.0.113.10',
            'metadata' => [
                'checkout_payment_method' => 'card',
                'card_last4' => '8680',
                'card_brand' => 'Mastercard',
            ],
        ], $orderOverrides));

        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);

        MemberStudentActivityLog::query()->create([
            'user_id' => $customer->id,
            'product_id' => (string) $product->id,
            'event' => MemberStudentActivityLog::EVENT_LOGIN,
            'ip' => '203.0.113.88',
        ]);

        return compact('seller', 'product', 'customer', 'order');
    }

    public function test_show_includes_contestacao_url_next_to_access_dossier(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer, 'order' => $order] = $this->customerWithMemberAccess();

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.show', $customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Customers/Show')
                ->where('orders.data.0.has_access_dossier', true)
                ->where(
                    'orders.data.0.contestacao_url',
                    route('plataforma.clientes.contestacao', ['user' => $customer->id, 'order' => $order->id])
                )
            );
    }

    public function test_admin_can_download_chargeback_defense_pdf_with_company_and_access_data(): void
    {
        $this->configureCompany();
        $admin = $this->platformAdmin();
        ['customer' => $customer, 'order' => $order] = $this->customerWithMemberAccess();

        $response = $this->actingAs($admin)->get(route('plataforma.clientes.contestacao', [
            'user' => $customer->id,
            'order' => $order->id,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $text = $this->pdfText($response->getContent());
        $this->assertStringContainsString('DEFESA ADMINISTRATIVA', $text);
        $this->assertStringContainsString('JAS MARKETING E DESENVOLVIMENTO LTDA', $text);
        $this->assertStringContainsString('c11521b5-6788-43b3-87da-f9af214da03b', $text);
        $this->assertStringContainsString('Vupt Transitions', $text);
        $this->assertStringContainsString('ggrsoares.rec@example.com', $text);
        $this->assertStringContainsString('390.533.447-05', $text);
        $this->assertStringContainsString('8680', $text);
        $this->assertStringContainsString('203.0.113.88', $text);
        $this->assertStringNotContainsString('WhatsApp', $text);
        $this->assertStringNotContainsString('estorno administrativo', $text);
        $this->assertStringNotContainsString('Getfy', $text);
        $this->assertStringNotContainsString('Gatefy', $text);

        if (Schema::hasTable('platform_audit_logs')) {
            $this->assertDatabaseHas('platform_audit_logs', [
                'action' => 'platform.customer.chargeback_defense.exported',
            ]);
        }
    }

    public function test_refunded_order_includes_refund_section_without_inventing_channel(): void
    {
        $this->configureCompany();
        ['order' => $order] = $this->customerWithMemberAccess([
            'status' => 'refunded',
            'metadata' => [
                'checkout_payment_method' => 'card',
                'manual_refund' => [
                    'initiated_by' => 'platform',
                    'initiated_by_user_id' => 1,
                    'initiated_by_name' => 'Admin',
                    'reason' => 'Cancelamento atendido',
                    'refunded_at' => now()->toIso8601String(),
                    'offline' => false,
                    'gateway_refund' => ['status' => 'refunded', 'note' => null],
                ],
            ],
        ]);

        $doc = app(ChargebackDefensePdfService::class)->buildDocument($order->fresh());

        $this->assertNotNull($doc['refund']);
        $this->assertTrue($doc['delivery']['granted']);
        $this->assertStringContainsString('estorno administrativo', $doc['subject']);
        $this->assertStringContainsString('reembolso em duplicidade', implode(' ', $doc['closing']));

        $pdf = app(ChargebackDefensePdfService::class)->renderPdf($order->fresh());
        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('ESTORNO ADMINISTRATIVO', $text);
        $this->assertStringNotContainsString('WhatsApp', $text);
    }

    public function test_document_omits_card_last4_when_metadata_has_none(): void
    {
        ['order' => $order] = $this->customerWithMemberAccess([
            'metadata' => ['checkout_payment_method' => 'pix'],
            'payment_method' => 'pix',
        ]);

        $doc = app(ChargebackDefensePdfService::class)->buildDocument($order->fresh());
        $payment = collect($doc['identification'])->firstWhere('label', 'Forma de pagamento');
        $this->assertIsArray($payment);
        $this->assertStringNotContainsString('final', (string) $payment['value']);
    }

    public function test_document_uses_empresa_not_getfy_when_legal_name_is_missing(): void
    {
        Setting::set(PlatformCompanySettings::KEY_LEGAL_NAME, '', null);
        config(['app.name' => 'Getfy', 'getfy.app_name' => 'Getfy']);
        ['order' => $order] = $this->customerWithMemberAccess();

        $doc = app(ChargebackDefensePdfService::class)->buildDocument($order->fresh());
        $this->assertSame('Empresa', $doc['company']['legal_name']);
        $this->assertStringNotContainsString('Getfy', $doc['intro']);
        $this->assertStringNotContainsString('Getfy', implode(' ', $doc['closing']));

        $text = $this->pdfText(app(ChargebackDefensePdfService::class)->renderPdf($order->fresh()));
        $this->assertStringContainsString('Empresa', $text);
        $this->assertStringNotContainsString('Getfy', $text);
        $this->assertStringNotContainsString('Gatefy', $text);
    }

    public function test_admin_cannot_export_order_of_another_customer(): void
    {
        $admin = $this->platformAdmin();
        ['customer' => $customer, 'seller' => $seller] = $this->customerWithMemberAccess();
        $other = User::factory()->create(['role' => User::ROLE_CLIENTE, 'tenant_id' => null]);
        $otherProduct = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Produto de outro cliente',
        ]);
        $foreign = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $other->id,
            'product_id' => $otherProduct->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $other->email,
            'payment_method' => 'pix',
            'gateway' => 'demo',
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.clientes.contestacao', [
                'user' => $customer->id,
                'order' => $foreign->id,
            ]))
            ->assertNotFound();
    }

    public function test_seller_cannot_export_platform_chargeback_defense(): void
    {
        $seller = $this->seller();
        ['customer' => $customer, 'order' => $order] = $this->customerWithMemberAccess();

        $this->actingAs($seller)
            ->get(route('plataforma.clientes.contestacao', [
                'user' => $customer->id,
                'order' => $order->id,
            ]))
            ->assertForbidden();
    }

    private function pdfText(string $binary): string
    {
        $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $binary);

        return $converted !== false ? $converted : $binary;
    }
}
