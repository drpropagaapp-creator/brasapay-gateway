<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\PlatformGa4Settings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformGa4SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_admin_saves_measurement_id_and_encrypted_api_secret(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->put('/plataforma/configuracoes', [
            'platform_ga4_enabled' => '1',
            'platform_ga4_measurement_id' => 'g-abc123xyz',
            'platform_ga4_api_secret' => 'segredo_ga4_123',
        ]);

        $response->assertRedirect();
        $this->assertTrue(PlatformGa4Settings::canSendServerPurchase());
        $this->assertSame('G-ABC123XYZ', PlatformGa4Settings::measurementId());
        $this->assertSame('segredo_ga4_123', PlatformGa4Settings::apiSecret());
        $stored = (string) Setting::get(PlatformGa4Settings::KEY_API_SECRET, '', null);
        $this->assertNotSame('segredo_ga4_123', $stored);
        $this->assertSame('segredo_ga4_123', Crypt::decryptString($stored));
    }

    public function test_settings_page_exposes_ga4_fields_without_the_secret(): void
    {
        Setting::set(PlatformGa4Settings::KEY_ENABLED, '1', null);
        Setting::set(PlatformGa4Settings::KEY_MEASUREMENT_ID, 'G-PAGE123', null);
        Setting::set(PlatformGa4Settings::KEY_API_SECRET, Crypt::encryptString('segredo_ga4_123'), null);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/plataforma/configuracoes?tab=google_analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('settings.platform_ga4_enabled', '1')
                ->where('settings.platform_ga4_measurement_id', 'G-PAGE123')
                ->where('settings.platform_ga4_api_secret_configured', true)
                ->missing('settings.platform_ga4_api_secret'));
    }

    public function test_blank_api_secret_keeps_the_saved_one(): void
    {
        Setting::set(PlatformGa4Settings::KEY_ENABLED, '1', null);
        Setting::set(PlatformGa4Settings::KEY_MEASUREMENT_ID, 'G-KEEP123', null);
        Setting::set(PlatformGa4Settings::KEY_API_SECRET, Crypt::encryptString('segredo_antigo'), null);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->put('/plataforma/configuracoes', [
            'platform_ga4_enabled' => '1',
            'platform_ga4_measurement_id' => 'G-KEEP123',
            'platform_ga4_api_secret' => '',
        ])->assertRedirect();

        $this->assertSame('segredo_antigo', PlatformGa4Settings::apiSecret());
    }

    public function test_enabling_without_measurement_id_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)
            ->from('/plataforma/configuracoes?tab=google_analytics')
            ->put('/plataforma/configuracoes', [
                'platform_ga4_enabled' => '1',
                'platform_ga4_measurement_id' => '',
                'platform_ga4_api_secret' => '',
            ]);

        $response->assertRedirect('/plataforma/configuracoes?tab=google_analytics');
        $response->assertSessionHasErrors('platform_ga4_measurement_id');
        $this->assertFalse(PlatformGa4Settings::isEnabled());
    }

    public function test_completed_order_sends_purchase_once(): void
    {
        Setting::set(PlatformGa4Settings::KEY_ENABLED, '1', null);
        Setting::set(PlatformGa4Settings::KEY_MEASUREMENT_ID, 'G-TEST1234', null);
        Setting::set(PlatformGa4Settings::KEY_API_SECRET, Crypt::encryptString('segredo_ga4_123'), null);

        Http::fake([
            'https://www.google-analytics.com/*' => Http::response('', 204),
        ]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['currency' => 'BRL', 'name' => 'Curso GA4']);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'comprador@example.com',
            'payment_method' => 'pix',
            'gateway' => 'manual',
            'metadata' => ['ga_client_id' => '111111.222222'],
        ]);

        event(new OrderCompleted($order));
        event(new OrderCompleted($order->fresh()));

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($order) {
            $event = $request->data()['events'][0] ?? [];

            return str_contains($request->url(), 'measurement_id=G-TEST1234')
                && str_contains($request->url(), 'api_secret=segredo_ga4_123')
                && ($event['name'] ?? '') === 'purchase'
                && ($event['params']['transaction_id'] ?? '') === $order->public_reference
                && ($event['params']['currency'] ?? '') === 'BRL'
                && (float) ($event['params']['value'] ?? 0) === 49.9;
        });

        $fresh = $order->fresh();
        $this->assertTrue((bool) ($fresh->metadata['ga4_mp_purchase_sent'] ?? false));
    }

    public function test_order_stores_ga_client_id_from_cookie(): void
    {
        Setting::set(PlatformGa4Settings::KEY_ENABLED, '1', null);
        Setting::set(PlatformGa4Settings::KEY_MEASUREMENT_ID, 'G-TEST1234', null);

        $this->app->instance('request', Request::create('/c/demo', 'GET', [], [
            '_ga' => 'GA1.1.424242.525252',
        ]));

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'a@b.com',
        ]);

        $this->assertSame('424242.525252', $order->fresh()->metadata['ga_client_id'] ?? null);
    }

    public function test_production_csp_allows_ga4_collect(): void
    {
        config(['app.env' => 'production']);

        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://www.google-analytics.com', $csp);
        $this->assertStringContainsString('https://*.google-analytics.com', $csp);
        $this->assertStringContainsString('https://stats.g.doubleclick.net', $csp);
    }
}
