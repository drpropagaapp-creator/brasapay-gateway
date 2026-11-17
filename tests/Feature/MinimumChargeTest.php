<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureSellerPanel;
use App\Models\ApiApplication;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\MinimumChargeService;
use App\Support\ApiScopes;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class MinimumChargeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureSellerPanel::class,
            ValidateCsrfToken::class,
        ]);
    }

    /**
     * @return array{app: ApiApplication, public: string, secret: string}
     */
    private function createApiApp(int $tenantId): array
    {
        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();

        $app = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => 'API App',
            'slug' => ApiApplication::generateUniqueSlug($tenantId, 'Min Charge API'),
            'api_key_hash' => ApiApplication::hashApiKey('legacy-unused'),
            'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
            'webhook_url' => null,
            'webhook_secret' => null,
        ]);

        return ['app' => $app, 'public' => $public, 'secret' => $secret];
    }

    public function test_platform_admin_can_update_charge_limits(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.limites.update'), [
            'api_pix_minimum_charge_brl' => 5,
            'platform_minimum_charge_brl' => 10,
            'platform_minimum_withdrawal_brl' => 25,
        ]);

        $response->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'limites']));

        $service = app(MinimumChargeService::class);
        $this->assertSame(5.0, $service->apiPixMinimumBrl());
        $this->assertSame(10.0, $service->platformMinimumBrl());
        $this->assertSame('5', Setting::get(MinimumChargeService::SETTING_API_PIX, null, null));
        $this->assertSame(25.0, \App\Services\Withdrawal\WithdrawalMinimumService::platformMinimumBrl());
    }

    public function test_platform_admin_can_set_maximum_ticket_and_withdrawal_interval(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->put(route('plataforma.financeiro.limites.update'), [
            'api_pix_minimum_charge_brl' => 5,
            'api_pix_maximum_charge_brl' => 500,
            'platform_minimum_charge_brl' => 10,
            'platform_maximum_charge_brl' => 1000,
            'platform_minimum_withdrawal_brl' => 25,
            'withdrawal_interval_enabled' => true,
            'withdrawal_interval_hours' => 12,
        ]);

        $response->assertRedirect(route('plataforma.financeiro.index', ['tab' => 'limites']));
        $response->assertSessionHasNoErrors();

        $service = app(MinimumChargeService::class);
        $this->assertSame(500.0, $service->apiPixMaximumBrl());
        $this->assertSame(1000.0, $service->platformMaximumBrl());
        $this->assertTrue(\App\Services\Withdrawal\WithdrawalPolicyService::intervalEnabled());
        $this->assertSame(12, \App\Services\Withdrawal\WithdrawalPolicyService::intervalHours());
    }

    public function test_maximum_below_minimum_is_rejected(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $response = $this->actingAs($admin)->from(route('plataforma.financeiro.index', ['tab' => 'limites']))
            ->put(route('plataforma.financeiro.limites.update'), [
                'api_pix_minimum_charge_brl' => 50,
                'api_pix_maximum_charge_brl' => 10,
                'platform_minimum_charge_brl' => 1,
                'platform_maximum_charge_brl' => 0,
                'platform_minimum_withdrawal_brl' => 0,
            ]);

        $response->assertSessionHasErrors('api_pix_maximum_charge_brl');
    }

    public function test_api_pix_above_maximum_returns_422(): void
    {
        Setting::set('api_pix_enabled', '1', null);
        Setting::set(MinimumChargeService::SETTING_API_PIX_MAX, '20', null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $keys = $this->createApiApp((int) $seller->id);

        $response = $this->withHeaders([
            'X-Public-Key' => $keys['public'],
            'X-Secret-Key' => $keys['secret'],
        ])->postJson('/api/v1/payments/pix', [
            'customer' => ['email' => 'cliente@exemplo.com', 'name' => 'Cliente'],
            'amount' => 20.01,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_product_store_above_platform_maximum_returns_422(): void
    {
        Setting::set(MinimumChargeService::SETTING_PLATFORM_MAX, '30', null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $response = $this->actingAs($seller)->post('/produtos', [
            'name' => 'Produto Caro',
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 30.01,
            'currency' => 'BRL',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('price');
    }

    public function test_api_pix_below_minimum_returns_422(): void
    {
        Setting::set('api_pix_enabled', '1', null);
        Setting::set(MinimumChargeService::SETTING_API_PIX, '5', null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $keys = $this->createApiApp((int) $seller->id);

        $response = $this->withHeaders([
            'X-Public-Key' => $keys['public'],
            'X-Secret-Key' => $keys['secret'],
        ])->postJson('/api/v1/payments/pix', [
            'customer' => ['email' => 'cliente@exemplo.com', 'name' => 'Cliente'],
            'amount' => 4.99,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_product_store_below_platform_minimum_returns_422(): void
    {
        Setting::set(MinimumChargeService::SETTING_PLATFORM, '10', null);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $response = $this->actingAs($seller)->post('/produtos', [
            'name' => 'Produto Barato',
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 9.99,
            'currency' => 'BRL',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('price');
    }
}
