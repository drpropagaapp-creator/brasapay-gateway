<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\LegalDocumentsService;
use App\Support\PlatformCompanySettings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformCompanySettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_defaults_are_empty(): void
    {
        Setting::query()->whereIn('key', [
            PlatformCompanySettings::KEY_LEGAL_NAME,
            PlatformCompanySettings::KEY_CNPJ,
            PlatformCompanySettings::KEY_CHECKOUT_NOTICE_ENABLED,
            PlatformCompanySettings::KEY_CHECKOUT_NOTICE,
        ])->delete();

        $this->assertSame('', PlatformCompanySettings::legalName());
        $this->assertSame('', PlatformCompanySettings::cnpjDigits());
        $this->assertSame('', PlatformCompanySettings::cnpjFormatted());
        $this->assertFalse(PlatformCompanySettings::isCheckoutNoticeEnabled());
        $this->assertNull(PlatformCompanySettings::resolvedCheckoutNoticeForTenant(1));
        $this->assertSame([
            'legal_name' => '',
            'cnpj' => '',
            'cnpj_formatted' => '',
        ], PlatformCompanySettings::publicPayload());
    }

    public function test_platform_admin_can_save_company_data(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'platform_legal_name' => '  Stacker Gateway LTDA  ',
                'platform_cnpj' => '11.222.333/0001-81',
            ])
            ->assertRedirect();

        $this->assertSame('Stacker Gateway LTDA', PlatformCompanySettings::legalName());
        $this->assertSame('11222333000181', PlatformCompanySettings::cnpjDigits());
        $this->assertSame('11.222.333/0001-81', PlatformCompanySettings::cnpjFormatted());
    }

    public function test_invalid_cnpj_is_rejected(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->from('/plataforma/configuracoes?tab=dados_plataforma')
            ->put('/plataforma/configuracoes', [
                'platform_legal_name' => 'Empresa Teste',
                'platform_cnpj' => '11.111.111/1111-11',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('platform_cnpj');

        Cache::forget('setting.global.'.PlatformCompanySettings::KEY_CNPJ);
        $this->assertSame('', PlatformCompanySettings::cnpjDigits());
    }

    public function test_empty_fields_clear_saved_values(): void
    {
        Setting::set(PlatformCompanySettings::KEY_LEGAL_NAME, 'Empresa Antiga', null);
        Setting::set(PlatformCompanySettings::KEY_CNPJ, '11222333000181', null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'platform_legal_name' => '',
                'platform_cnpj' => '',
            ])
            ->assertRedirect();

        $this->assertSame('', PlatformCompanySettings::legalName());
        $this->assertSame('', PlatformCompanySettings::cnpjDigits());
    }

    public function test_settings_page_exposes_company_data(): void
    {
        Setting::set(PlatformCompanySettings::KEY_LEGAL_NAME, 'Stacker Gateway LTDA', null);
        Setting::set(PlatformCompanySettings::KEY_CNPJ, '11222333000181', null);

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/configuracoes?tab=dados_plataforma')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('settings.platform_legal_name', 'Stacker Gateway LTDA')
                ->where('settings.platform_cnpj', '11.222.333/0001-81')
                ->where('settings.platform_checkout_notice_enabled', '0'));
    }

    public function test_checkout_notice_is_hidden_when_disabled(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'name' => 'João Vendedor',
            'email' => 'joao@seller.test',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set(PlatformCompanySettings::KEY_LEGAL_NAME, 'Stacker Gateway LTDA', null);
        Setting::set(PlatformCompanySettings::KEY_CNPJ, '11222333000181', null);
        Setting::set(PlatformCompanySettings::KEY_CHECKOUT_NOTICE_ENABLED, '0', null);
        Setting::set(PlatformCompanySettings::KEY_CHECKOUT_NOTICE, PlatformCompanySettings::DEFAULT_CHECKOUT_NOTICE, null);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'noticeoff',
            'checkout_config' => [
                'customer_fields' => ['name' => false, 'cpf' => false, 'phone' => false, 'coupon' => false],
            ],
        ]);

        $this->get('/c/'.$product->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Show')
                ->where('platform_checkout_notice', null));
    }

    public function test_checkout_notice_replaces_variables_when_enabled(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'name' => 'João Vendedor',
            'email' => 'joao@seller.test',
            'trade_name' => 'Loja do João',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set(PlatformCompanySettings::KEY_LEGAL_NAME, 'Stacker Gateway LTDA', null);
        Setting::set(PlatformCompanySettings::KEY_CNPJ, '11222333000181', null);
        Setting::set(PlatformCompanySettings::KEY_CHECKOUT_NOTICE_ENABLED, '1', null);
        Setting::set(LegalDocumentsService::SETTING_PRIVACY_EMAIL, 'contato@plataforma.test', null);
        Setting::set(
            PlatformCompanySettings::KEY_CHECKOUT_NOTICE,
            PlatformCompanySettings::DEFAULT_CHECKOUT_NOTICE."\nVendido por {empresa}.",
            null
        );

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'noticeon',
            'checkout_config' => [
                'customer_fields' => ['name' => false, 'cpf' => false, 'phone' => false, 'coupon' => false],
            ],
        ]);

        $response = $this->get('/c/'.$product->checkout_slug)->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Checkout/Show')
            ->where('platform_checkout_notice', function ($notice) {
                $text = (string) data_get($notice, 'pt_BR', '');
                $this->assertNotSame('', $text);
                $this->assertStringContainsString('Stacker Gateway LTDA', $text);
                $this->assertStringContainsString('11.222.333/0001-81', $text);
                $this->assertStringContainsString('João Vendedor', $text);
                $this->assertStringContainsString('joao@seller.test', $text);
                $this->assertStringContainsString('Loja do João', $text);
                $this->assertStringNotContainsString('{cnpj}', $text);
                $this->assertStringNotContainsString('{infoprodutor}', $text);
                $this->assertStringNotContainsString('{empresa}', $text);
                $this->assertStringContainsString('{termos}', $text);
                $this->assertStringContainsString('{privacidade}', $text);
                $this->assertStringNotContainsString('<a ', $text);

                return true;
            }));
    }

    public function test_checkout_notice_replaces_product_support_email(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'name' => 'João Vendedor',
            'email' => 'joao@seller.test',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set(PlatformCompanySettings::KEY_CHECKOUT_NOTICE_ENABLED, '1', null);
        Setting::set(
            PlatformCompanySettings::KEY_CHECKOUT_NOTICE,
            'Dúvidas: {email_suporte_produto}',
            null
        );

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'noticeemail',
            'support_email' => 'suporte.curso@loja.test',
            'checkout_config' => [
                'customer_fields' => ['name' => false, 'cpf' => false, 'phone' => false, 'coupon' => false],
            ],
        ]);

        $this->get('/c/'.$product->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Show')
                ->where('platform_checkout_notice', function ($notice) {
                    $text = (string) data_get($notice, 'pt_BR', '');
                    $this->assertStringContainsString('suporte.curso@loja.test', $text);
                    $this->assertStringNotContainsString('{email_suporte_produto}', $text);

                    return true;
                }));
    }

    public function test_platform_admin_can_save_checkout_notice(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'platform_legal_name' => 'Empresa Aviso LTDA',
                'platform_cnpj' => '11.222.333/0001-81',
                'platform_checkout_notice_enabled' => true,
                'platform_checkout_notice' => 'Compra na {plataforma} — {razao_social} CNPJ {cnpj}.',
            ])
            ->assertRedirect();

        $this->assertTrue(PlatformCompanySettings::isCheckoutNoticeEnabled());
        $this->assertSame(
            'Compra na {plataforma} — {razao_social} CNPJ {cnpj}.',
            PlatformCompanySettings::decodeCheckoutNotices(
                Setting::get(PlatformCompanySettings::KEY_CHECKOUT_NOTICE, '', null)
            )['pt_BR']
        );
    }

    public function test_platform_admin_can_save_checkout_notice_per_locale(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'platform_legal_name' => 'Empresa Aviso LTDA',
                'platform_cnpj' => '11.222.333/0001-81',
                'platform_checkout_notice_enabled' => true,
                'platform_checkout_notice' => [
                    'pt_BR' => 'Aviso em {plataforma} PT',
                    'en' => 'Notice on {plataforma} EN',
                    'es' => 'Aviso en {plataforma} ES',
                ],
            ])
            ->assertRedirect();

        $stored = PlatformCompanySettings::decodeCheckoutNotices(
            Setting::get(PlatformCompanySettings::KEY_CHECKOUT_NOTICE, '', null)
        );
        $this->assertSame('Aviso em {plataforma} PT', $stored['pt_BR']);
        $this->assertSame('Notice on {plataforma} EN', $stored['en']);
        $this->assertSame('Aviso en {plataforma} ES', $stored['es']);
    }

    public function test_checkout_notice_keeps_legacy_plain_string_as_portuguese(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'name' => 'Ana Vendedora',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        Setting::set(PlatformCompanySettings::KEY_CHECKOUT_NOTICE_ENABLED, '1', null);
        Setting::set(PlatformCompanySettings::KEY_CHECKOUT_NOTICE, 'Texto legado {infoprodutor}', null);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'noticelegacy',
            'checkout_config' => [
                'customer_fields' => ['name' => false, 'cpf' => false, 'phone' => false, 'coupon' => false],
            ],
        ]);

        $this->get('/c/'.$product->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Show')
                ->where('platform_checkout_notice', function ($notice) {
                    $this->assertSame('Texto legado Ana Vendedora', data_get($notice, 'pt_BR'));
                    $this->assertNull(data_get($notice, 'en'));

                    return true;
                }));
    }
}
