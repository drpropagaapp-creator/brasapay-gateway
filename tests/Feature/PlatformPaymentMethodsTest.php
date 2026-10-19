<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Services\PaymentService;
use App\Services\PlatformPaymentMethods;
use Tests\TestCase;

class PlatformPaymentMethodsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('gateway_order', [
            'pix' => ['efi'],
            'card' => ['cajupay'],
            'boleto' => [],
            'pix_auto' => [],
        ], null);
    }

    public function test_platform_disabled_apple_pay_not_in_checkout_methods(): void
    {
        Setting::set('platform_payment_methods_enabled', [
            'pix' => true,
            'card' => true,
            'boleto' => true,
            'pix_auto' => true,
            'apple_pay' => false,
            'google_pay' => true,
        ], null);

        $product = $this->createTestProduct(['tenant_id' => 1]);

        $credPix = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'efi',
            'is_connected' => true,
        ]);
        $credPix->setEncryptedCredentials(['payee_code' => '123', 'sandbox' => true]);
        $credPix->save();

        $credCard = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $credCard->setEncryptedCredentials(['public_key' => 'pk', 'secret_key' => 'sk']);
        $credCard->save();

        $global = app(PaymentService::class)->globallyAvailablePaymentMethodKeys($product, null);
        $this->assertFalse($global['apple_pay']);
        $this->assertTrue($global['google_pay']);

        $methods = app(PaymentService::class)->availablePaymentMethodsForCheckout($product, null, null);
        $ids = array_column($methods, 'id');
        $this->assertNotContains('apple_pay', $ids);
        $this->assertContains('google_pay', $ids);
        $google = collect($methods)->firstWhere('id', 'google_pay');
        $this->assertSame('cajupay', $google['gateway_slug'] ?? null);
        $this->assertSame('CajuPay', $google['wallet_via'] ?? null);
    }

    public function test_wallet_priority_uses_stripe_when_it_is_first(): void
    {
        Setting::set('gateway_order', [
            'pix' => ['efi'],
            'card' => ['cajupay'],
            'boleto' => [],
            'pix_auto' => [],
            'wallet' => ['stripe', 'cajupay'],
        ], null);

        $product = $this->createTestProduct(['tenant_id' => 1]);

        $credCard = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $credCard->setEncryptedCredentials(['public_key' => 'pk', 'secret_key' => 'sk']);
        $credCard->save();

        $credStripe = new GatewayCredential([
            'tenant_id' => null,
            'gateway_slug' => 'stripe',
            'is_connected' => true,
        ]);
        $credStripe->setEncryptedCredentials([
            'secret_key' => 'sk_test_wallet',
            'publishable_key' => 'pk_test_wallet',
        ]);
        $credStripe->save();

        $methods = app(PaymentService::class)->availablePaymentMethodsForCheckout($product, null, null);
        $apple = collect($methods)->firstWhere('id', 'apple_pay');
        $google = collect($methods)->firstWhere('id', 'google_pay');

        $this->assertSame('stripe', $apple['gateway_slug'] ?? null);
        $this->assertSame('Stripe', $apple['wallet_via'] ?? null);
        $this->assertSame('stripe', $google['gateway_slug'] ?? null);
        $this->assertSame('Stripe', app(PaymentService::class)->walletAcquirerLabel(1, $product));
    }

    public function test_platform_payment_methods_defaults_all_enabled(): void
    {
        $enabled = PlatformPaymentMethods::platformEnabled();
        foreach (PlatformPaymentMethods::METHOD_KEYS as $key) {
            $this->assertTrue($enabled[$key], "Expected {$key} enabled by default");
        }
    }
}
