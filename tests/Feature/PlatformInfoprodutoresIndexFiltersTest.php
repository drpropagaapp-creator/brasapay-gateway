<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApiPixAccess;
use App\Services\Med\MedPolicyService;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class PlatformInfoprodutoresIndexFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_email_search_ignores_letter_case(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $seller = $this->seller([
            'name' => 'Conta Mista',
            'email' => 'Seller.Upper@Example.com',
        ]);
        $this->seller([
            'name' => 'Outra Conta',
            'email' => 'outra@example.com',
        ]);

        $response = $this->actingAs($admin)->get('/plataforma/usuarios?q='.urlencode('SELLER.UPPER@EXAMPLE.COM'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Platform/Users/Index')
            ->has('users.data', 1)
            ->where('users.data.0.id', $seller->id)
        );
    }

    public function test_feature_filter_and_api_pix_badge(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        Setting::set('api_pix_enabled', false, null);
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::TELEGRAM, false);

        $custom = $this->seller([
            'name' => 'Com taxas',
            'email' => 'taxas@example.com',
            'merchant_fees' => ['pix' => ['percent' => 2.5, 'fixed' => 0]],
            'merchant_settlement_overrides' => ['pix' => ['days_to_available' => 3]],
        ]);
        $apiPix = $this->seller([
            'name' => 'Com API PIX',
            'email' => 'apipix@example.com',
        ]);
        ApiPixAccess::setTenantMode((int) $apiPix->tenant_id, ApiPixAccess::MODE_ENABLED);
        app(MedPolicyService::class)->setMedZeroForTenant((int) $apiPix->tenant_id, true);
        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::TELEGRAM,
            (int) $apiPix->tenant_id,
            SellerIntegrationVisibility::MODE_ENABLED
        );

        $fees = $this->actingAs($admin)->get('/plataforma/usuarios?feature=taxas_custom');
        $fees->assertOk();
        $fees->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $custom->id)
        );

        $settlement = $this->actingAs($admin)->get('/plataforma/usuarios?feature=liquidacao_custom');
        $settlement->assertOk();
        $settlement->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $custom->id)
        );

        $pix = $this->actingAs($admin)->get('/plataforma/usuarios?feature=api_pix');
        $pix->assertOk();
        $pix->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $apiPix->id)
            ->where('users.data.0.api_pix_enabled_effective', true)
        );

        $med = $this->actingAs($admin)->get('/plataforma/usuarios?feature=med_zero');
        $med->assertOk();
        $med->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $apiPix->id)
        );

        $telegram = $this->actingAs($admin)->get('/plataforma/usuarios?feature=integration_telegram');
        $telegram->assertOk();
        $telegram->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $apiPix->id)
        );

        $all = $this->actingAs($admin)->get('/plataforma/usuarios');
        $all->assertOk();
        $all->assertInertia(fn ($page) => $page
            ->has('users.data', 2)
            ->where('users.data', function ($rows) use ($custom, $apiPix) {
                $byId = collect($rows)->keyBy('id');

                return $byId[$custom->id]['api_pix_enabled_effective'] === false
                    && $byId[$apiPix->id]['api_pix_enabled_effective'] === true;
            })
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seller(array $attributes): User
    {
        $seller = User::factory()->create(array_merge([
            'role' => User::ROLE_INFOPRODUTOR,
        ], $attributes));
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }
}
