<?php

namespace Tests\Unit;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Services\CajuPay\CajuPaySdkCheckoutService;
use App\Support\CajuPayBrowserSdk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPaySdkSessionCreateTest extends TestCase
{
    public function test_create_sdk_session_sends_card_installment_and_locale_flags(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/sdk/v1/checkout/sessions' => function ($request) {
                $body = $request->data();
                $this->assertSame(9900, $body['amount_cents'] ?? null);
                $this->assertTrue($body['allow_card'] ?? false);
                $this->assertFalse($body['allow_pix'] ?? true);
                $this->assertTrue($body['allow_card_installments'] ?? false);
                $this->assertSame(6, $body['card_max_installments'] ?? null);
                $this->assertSame('pt-BR', $body['locale'] ?? null);
                $this->assertSame('https://loja.exemplo.com/c/curso', $body['partner_checkout_url'] ?? null);

                return Http::response([
                    'token' => 'tok_public_abc',
                    'checkout_session_id' => 'sess-uuid-123',
                ], 201);
            },
        ]);

        $driver = new CajuPayDriver;
        $result = $driver->createSdkCheckoutSession(
            ['public_key' => 'pk_test', 'secret_key' => 'sk_test'],
            9900,
            'Pedido #1',
            'ext-1',
            [],
            ['card'],
            'card',
            [
                'allow_card_installments' => true,
                'card_max_installments' => 6,
                'locale' => 'pt-BR',
                'partner_checkout_url' => 'https://loja.exemplo.com/c/curso',
            ]
        );

        $this->assertSame('tok_public_abc', $result['token']);
        $this->assertSame('sess-uuid-123', $result['checkout_session_id']);
    }

    public function test_create_sdk_session_sends_installments_false_when_platform_disallows(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/sdk/v1/checkout/sessions' => function ($request) {
                $body = $request->data();
                $this->assertArrayHasKey('allow_card_installments', $body);
                $this->assertFalse($body['allow_card_installments']);
                $this->assertSame(1, $body['card_max_installments'] ?? null);

                return Http::response([
                    'token' => 'tok_public_off',
                    'checkout_session_id' => 'sess-uuid-off',
                ], 201);
            },
        ]);

        $driver = new CajuPayDriver;
        $driver->createSdkCheckoutSession(
            ['public_key' => 'pk_test', 'secret_key' => 'sk_test'],
            9900,
            'Pedido #1',
            'ext-1',
            [],
            ['card'],
            'card',
            ['allow_card_installments' => false]
        );
    }

    public function test_create_sdk_session_sends_installments_false_when_options_omitted(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/sdk/v1/checkout/sessions' => function ($request) {
                $body = $request->data();
                $this->assertFalse($body['allow_card_installments'] ?? true);
                $this->assertSame(1, $body['card_max_installments'] ?? null);

                return Http::response([
                    'token' => 'tok_public_omit',
                    'checkout_session_id' => 'sess-uuid-omit',
                ], 201);
            },
        ]);

        $driver = new CajuPayDriver;
        $driver->createSdkCheckoutSession(
            ['public_key' => 'pk_test', 'secret_key' => 'sk_test'],
            9900,
            'Pedido #1',
            'ext-1',
            [],
            ['card'],
            'card'
        );
    }

    public function test_create_sdk_session_rejects_amount_below_two_reais(): void
    {
        $driver = new CajuPayDriver;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('valor mínimo de cobrança é R$ 2,00');

        $driver->createSdkCheckoutSession(
            ['public_key' => 'pk_test', 'secret_key' => 'sk_test'],
            199,
            'Pedido #1',
            'ext-1',
            [],
            ['card'],
            'card'
        );
    }

    public function test_card_installment_session_options_require_enabled_product_and_amount(): void
    {
        $off = CajuPaySdkCheckoutService::cardInstallmentSessionOptions(
            ['card_installments' => ['enabled' => false, 'max' => 12]],
            99.0,
            false,
            'card'
        );
        $this->assertFalse($off['allow_card_installments']);
        $this->assertSame(1, $off['card_max_installments']);

        $wallet = CajuPaySdkCheckoutService::cardInstallmentSessionOptions(
            ['card_installments' => ['enabled' => true, 'max' => 6]],
            99.0,
            false,
            'apple_pay'
        );
        $this->assertFalse($wallet['allow_card_installments']);
        $this->assertSame(1, $wallet['card_max_installments']);

        $on = CajuPaySdkCheckoutService::cardInstallmentSessionOptions(
            ['card_installments' => ['enabled' => true, 'max' => 6]],
            99.0,
            false,
            'card'
        );
        $this->assertTrue($on['allow_card_installments']);
        $this->assertSame(6, $on['card_max_installments']);
    }

    public function test_locale_from_checkout_maps_underscore_to_bcp47(): void
    {
        $this->assertSame('pt-BR', CajuPayBrowserSdk::localeFromCheckout('pt_BR'));
        $this->assertSame('en', CajuPayBrowserSdk::localeFromCheckout('en'));
        $this->assertSame('auto', CajuPayBrowserSdk::localeFromCheckout('auto'));
    }

    public function test_partner_checkout_url_prefers_referer(): void
    {
        $request = Request::create('https://loja.exemplo.com/checkout/internal', 'POST', [], [], [], [
            'HTTP_REFERER' => 'https://loja.exemplo.com/c/curso',
        ]);

        $this->assertSame(
            'https://loja.exemplo.com/c/curso',
            CajuPayBrowserSdk::partnerCheckoutUrl($request)
        );
    }
}
