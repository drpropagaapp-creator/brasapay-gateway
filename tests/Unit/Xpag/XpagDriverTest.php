<?php

namespace Tests\Unit\Xpag;

use App\Gateways\GatewayRegistry;
use App\Gateways\Xpag\XpagDriver;
use App\Services\PaymentService;
use App\Services\Platform\AcquirerWalletBalanceService;
use App\Support\GatewayApiCredentials;
use App\Support\GatewayWebhookUrl;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XpagDriverTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        return [
            'client_id' => 'xpagsandbox_00000000',
            'client_secret' => '202620262026202620262026',
        ];
    }

    public function test_xpag_is_registered_as_pix_acquirer_including_api_pix(): void
    {
        $this->assertTrue(GatewayRegistry::isAllowedAcquirer('xpag'));
        $def = GatewayRegistry::get('xpag');
        $this->assertNotNull($def);
        $this->assertContains('pix', $def['methods'] ?? []);
        $this->assertNotContains('card', $def['methods'] ?? []);
        $this->assertSame('images/gateways/xpag_logo.svg', $def['image'] ?? null);
        $this->assertInstanceOf(XpagDriver::class, GatewayRegistry::driver('xpag'));
        $this->assertContains('xpag', config('gateways.default_order.pix'));
        $this->assertNotContains('xpag', config('gateways.api_pix_excluded_slugs'));
        $this->assertTrue(GatewayApiCredentials::isReadyForGateway('xpag', $this->credentials()));
        $this->assertFalse(GatewayApiCredentials::isReadyForGateway('xpag', ['client_id' => 'x']));

        $service = app(PaymentService::class);
        $apiOrder = new Order(['metadata' => ['source' => 'api']]);
        $this->assertTrue($service->isPixAcquirerAllowedForOrder('xpag', $apiOrder));
    }

    public function test_webhook_url_is_public(): void
    {
        config([
            'app.url' => 'http://localhost:8085',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/xpag',
            GatewayWebhookUrl::forGateway('xpag')
        );
    }

    public function test_create_pix_sends_reais_and_maps_brcode(): void
    {
        Http::fake([
            'api.xpag.global/cashin' => Http::response([
                'ok' => true,
                'qr_img' => 'data:image/png;base64,AAA',
                'copyPaste' => '00020126580014br.gov.bcb.pix',
                'code' => '00020126580014br.gov.bcb.pix',
                'request_number' => 'pr_abc',
                'transaction_id' => 'pr_abc',
            ], 200),
        ]);

        $result = (new XpagDriver)->createPixPayment(
            $this->credentials(),
            13.45,
            ['name' => 'Cliente Pix', 'document' => '11119854997'],
            '42',
            'https://pay.exemplo.com/webhooks/gateways/xpag'
        );

        $this->assertSame('pr_abc', $result['transaction_id']);
        $this->assertSame('00020126580014br.gov.bcb.pix', $result['copy_paste']);
        $this->assertSame('data:image/png;base64,AAA', $result['qrcode']);
        $this->assertSame('42', $result['metadata']['xpag_external_id'] ?? null);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/cashin') || $request->method() !== 'POST') {
                return false;
            }
            $data = $request->data();

            return ($data['currency'] ?? null) === 'BRL'
                && ($data['amount'] ?? null) === 13.45
                && ($data['external_id'] ?? null) === '42'
                && ($data['document'] ?? null) === '11119854997'
                && ($data['webhook_url'] ?? null) === 'https://pay.exemplo.com/webhooks/gateways/xpag'
                && $request->hasHeader('X-Client-Id', 'xpagsandbox_00000000');
        });
    }

    public function test_create_pix_surfaces_error_code(): void
    {
        Http::fake([
            'api.xpag.global/cashin' => Http::response([
                'ok' => false,
                'error' => 'Chave recusada.',
                'error_code' => 'invalid_credentials',
            ], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_credentials');

        (new XpagDriver)->createPixPayment(
            $this->credentials(),
            10,
            ['name' => 'Cliente'],
            '1',
            'https://pay.exemplo.com/webhooks/gateways/xpag'
        );
    }

    public function test_withdraw_sends_dict_key_in_reais(): void
    {
        Http::fake([
            'api.xpag.global/cashout' => Http::response([
                'ok' => true,
                'e2e' => 'E20261020',
                'amount' => 80,
                'fee' => 1.2,
                'status' => 'pending',
            ], 200),
        ]);

        $result = (new XpagDriver)->createTransfer(
            $this->credentials(),
            80.00,
            '11119854997',
            'cpf',
            '99',
            'Fulano',
            '11119854997',
            'https://pay.exemplo.com/webhooks/gateways/xpag'
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('E20261020', $result['transaction_id']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/cashout') || $request->method() !== 'POST') {
                return false;
            }
            $data = $request->data();

            return ($data['currency'] ?? null) === 'BRL'
                && (float) ($data['amount'] ?? 0) === 80.0
                && ($data['pix_key'] ?? null) === '11119854997'
                && ($data['pix_type'] ?? null) === 'CPF'
                && ($data['external_id'] ?? null) === '99'
                && ! array_key_exists('splits', $data);
        });
    }

    public function test_balance_parser_uses_brl_available(): void
    {
        $available = app(AcquirerWalletBalanceService::class)->parseXpagAvailable([
            'ok' => true,
            'balances' => [
                'BRL' => ['available' => 1530.75, 'blocked' => 200.00],
            ],
        ]);

        $this->assertSame(1530.75, $available);
    }

    public function test_test_connection_uses_balance_endpoint(): void
    {
        Http::fake([
            'api.xpag.global/balance' => Http::response([
                'ok' => true,
                'balances' => [
                    'BRL' => ['available' => 10, 'blocked' => 0],
                ],
            ], 200),
        ]);

        $this->assertTrue((new XpagDriver)->testConnection($this->credentials()));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.xpag.global/balance'
            && $request->method() === 'GET');
    }
}
