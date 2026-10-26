<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketplaceSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformMarketplaceTest extends TestCase
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

    private function seller(array $overrides = []): User
    {
        $seller = User::factory()->create(array_merge([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'password' => Hash::make('password'),
        ], $overrides));
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    public function test_auto_accept_defaults_to_enabled(): void
    {
        $this->assertTrue(MarketplaceSettings::autoAcceptEnabled());
    }

    public function test_admin_marketplace_lists_opted_in_products(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller(['name' => 'Loja Norte', 'email' => 'norte@example.com']);

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso marketplace',
            'checkout_slug' => 'curso-mkt-admin',
            'category' => 'educacao_e_cursos',
            'show_in_marketplace' => true,
            'marketplace_approved' => false,
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);
        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso fora',
            'checkout_slug' => 'curso-fora-mkt',
            'show_in_marketplace' => false,
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.marketplace.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Marketplace/Index')
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Curso marketplace')
                ->where('products.data.0.infoprodutor_email', 'norte@example.com')
                ->where('products.data.0.marketplace_approved', false)
                ->where('auto_accept', true)
                ->where('counts.pending', 1)
            );
    }

    public function test_admin_can_filter_marketplace_by_category_and_seller(): void
    {
        $admin = $this->platformAdmin();
        $alpha = $this->seller([
            'name' => 'Ana Souza',
            'email' => 'ana.souza@example.com',
            'phone' => '11988887777',
        ]);
        $beta = $this->seller([
            'name' => 'Bruno Lima',
            'email' => 'bruno@example.com',
            'phone' => '21977776666',
        ]);

        $this->createTestProduct([
            'tenant_id' => $alpha->id,
            'name' => 'Curso Tech Ana',
            'checkout_slug' => 'tech-ana',
            'category' => 'tecnologia_e_inovacao',
            'show_in_marketplace' => true,
            'marketplace_approved' => true,
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);
        $this->createTestProduct([
            'tenant_id' => $beta->id,
            'name' => 'Curso Saude Bruno',
            'checkout_slug' => 'saude-bruno',
            'category' => 'saude_e_bem_estar',
            'show_in_marketplace' => true,
            'marketplace_approved' => true,
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.marketplace.index', ['category' => 'tecnologia_e_inovacao']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Curso Tech Ana'));

        $this->actingAs($admin)
            ->get(route('plataforma.marketplace.index', ['seller' => 'ana.souza']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Curso Tech Ana'));

        $this->actingAs($admin)
            ->get(route('plataforma.marketplace.index', ['seller' => '1198888']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.infoprodutor_phone', '11988887777'));
    }

    public function test_admin_can_toggle_auto_accept_and_authorize_products(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso pendente',
            'checkout_slug' => 'curso-pendente-mkt',
            'show_in_marketplace' => true,
            'marketplace_approved' => false,
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.marketplace.auto-accept'), ['auto_accept' => false])
            ->assertRedirect();
        $this->assertFalse(MarketplaceSettings::autoAcceptEnabled());

        $this->actingAs($admin)
            ->post(route('plataforma.marketplace.authorize', $product))
            ->assertRedirect();
        $this->assertTrue((bool) $product->fresh()->marketplace_approved);

        $this->actingAs($admin)
            ->post(route('plataforma.marketplace.revoke', $product))
            ->assertRedirect();
        $this->assertFalse((bool) $product->fresh()->marketplace_approved);
    }

    public function test_seller_opt_in_is_auto_approved_when_setting_enabled(): void
    {
        Setting::set(MarketplaceSettings::KEY, '1', null);
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'price' => 10,
            'currency' => 'BRL',
            'category' => 'educacao_e_cursos',
            'show_in_marketplace' => false,
            'marketplace_approved' => false,
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

        $fresh = $product->fresh();
        $this->assertTrue((bool) $fresh->show_in_marketplace);
        $this->assertTrue((bool) $fresh->marketplace_approved);
    }

    public function test_seller_opt_in_stays_pending_when_auto_accept_disabled(): void
    {
        Setting::set(MarketplaceSettings::KEY, '0', null);
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'price' => 10,
            'currency' => 'BRL',
            'category' => 'educacao_e_cursos',
            'show_in_marketplace' => false,
            'marketplace_approved' => false,
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

        $fresh = $product->fresh();
        $this->assertTrue((bool) $fresh->show_in_marketplace);
        $this->assertFalse((bool) $fresh->marketplace_approved);
    }

    public function test_seller_cannot_open_admin_marketplace(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)
            ->get(route('plataforma.marketplace.index'))
            ->assertForbidden();
    }
}
