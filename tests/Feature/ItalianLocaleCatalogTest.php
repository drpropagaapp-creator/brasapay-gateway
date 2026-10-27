<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use App\Support\PlatformCompanySettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class ItalianLocaleCatalogTest extends TestCase
{
    public function test_italian_panel_catalog_covers_portuguese_keys(): void
    {
        $pt = array_keys((array) config('panel_i18n.locales.pt_BR', []));
        $it = array_keys((array) config('panel_i18n.locales.it', []));

        $this->assertNotSame([], $pt);
        $this->assertEqualsCanonicalizing($pt, $it);
        $itMessages = (array) config('panel_i18n.locales.it', []);
        $this->assertSame('Lingua', $itMessages['header.language'] ?? null);
        $this->assertSame('I miei acquisti', $itMessages['customer.purchases.title'] ?? null);
    }

    public function test_checkout_italian_catalog_covers_portuguese_keys(): void
    {
        $pt = array_keys((array) config('checkout_translations.pt_BR', []));
        $it = array_keys((array) config('checkout_translations.it', []));

        $this->assertNotSame([], $pt);
        $this->assertEqualsCanonicalizing($pt, $it);
        $this->assertSame('Acquisto 100% sicuro', ((array) config('checkout_translations.it', []))['checkout.secure_purchase'] ?? null);
    }

    public function test_checkout_notice_defaults_include_italian(): void
    {
        $defaults = PlatformCompanySettings::defaultCheckoutNotices();

        $this->assertArrayHasKey('it', $defaults);
        $this->assertStringContainsString('{plataforma}', $defaults['it']);
        $this->assertSame(
            ['pt_BR', 'en', 'es', 'it'],
            PlatformCompanySettings::CHECKOUT_NOTICE_LOCALES
        );
    }

    public function test_customer_receives_i18n_and_can_switch_to_italian(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $this->actingAs($buyer)
            ->get('/painel-cliente')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Cliente/Index')
                ->has('i18n.locale')
                ->has('i18n.available_languages')
                ->has('i18n.messages')
                ->where('pageTitle', 'Minhas compras'));

        $this->actingAs($buyer)
            ->post('/painel/idioma', ['locale' => 'it'])
            ->assertOk()
            ->assertJson(['ok' => true, 'locale' => 'it']);

        $this->actingAs($buyer)
            ->get('/painel-cliente')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Cliente/Index')
                ->where('i18n.locale', 'it')
                ->where('pageTitle', 'I miei acquisti')
                ->where('i18n.messages', function ($messages) {
                    $this->assertSame('I miei acquisti', $messages['customer.sidebar.purchases'] ?? null);

                    return true;
                }));
    }
}
