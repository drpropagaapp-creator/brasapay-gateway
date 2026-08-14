<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\SellerDashboardTemplate;
use Tests\TestCase;

class SellerDashboardTemplateTest extends TestCase
{
    public function test_platform_admin_can_read_and_save_dashboard_template(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->getJson(route('plataforma.settings.dashboard-template.data'))
            ->assertOk()
            ->assertJsonPath('template', 'default');

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.dashboard-template.update'), [
                'template' => 'aurora',
            ])
            ->assertOk()
            ->assertJsonPath('template', 'aurora');

        $this->assertSame('aurora', Setting::get(SellerDashboardTemplate::KEY, null, null));
        $this->assertSame('aurora', SellerDashboardTemplate::current());
    }

    public function test_invalid_template_returns_422(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.dashboard-template.update'), [
                'template' => 'invalid-theme',
            ])
            ->assertStatus(422);
    }

    public function test_platform_admin_can_save_kawaii_template(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.dashboard-template.update'), [
                'template' => 'kawaii',
            ])
            ->assertOk()
            ->assertJsonPath('template', 'kawaii');

        $this->assertSame('kawaii', SellerDashboardTemplate::current());
    }

    public function test_platform_admin_can_save_prime_template(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.dashboard-template.update'), [
                'template' => 'prime',
            ])
            ->assertOk()
            ->assertJsonPath('template', 'prime');

        $this->assertSame('prime', SellerDashboardTemplate::current());
    }

    public function test_platform_admin_can_save_studio_template(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('plataforma.settings.dashboard-template.update'), [
                'template' => 'studio',
            ])
            ->assertOk()
            ->assertJsonPath('template', 'studio');

        $this->assertSame('studio', SellerDashboardTemplate::current());
    }

    public function test_infoprodutor_dashboard_receives_studio_template_prop(): void
    {
        Setting::set(SellerDashboardTemplate::KEY, SellerDashboardTemplate::STUDIO, null);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->where('seller_dashboard_template', 'studio')
            );
    }

    public function test_infoprodutor_dashboard_receives_prime_template_prop(): void
    {
        Setting::set(SellerDashboardTemplate::KEY, SellerDashboardTemplate::PRIME, null);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->where('seller_dashboard_template', 'prime')
            );
    }

    public function test_infoprodutor_dashboard_receives_template_prop(): void
    {
        Setting::set(SellerDashboardTemplate::KEY, SellerDashboardTemplate::AURORA, null);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->where('seller_dashboard_template', 'aurora')
            );
    }

    public function test_infoprodutor_dashboard_receives_kawaii_template_prop(): void
    {
        Setting::set(SellerDashboardTemplate::KEY, SellerDashboardTemplate::KAWAII, null);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->where('seller_dashboard_template', 'kawaii')
            );
    }
}
