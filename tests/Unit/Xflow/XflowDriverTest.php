<?php

namespace Tests\Unit\Xflow;

use App\Gateways\GatewayRegistry;
use App\Gateways\Xflow\XflowDriver;
use App\Services\Platform\AcquirerWalletBalanceService;
use App\Services\Xflow\XflowWebhookBootstrapService;
use App\Support\GatewayApiCredentials;
use App\Support\GatewayWebhookUrl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XflowDriverTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'public_key' => 'pk_live_test',
            'secret_key' => 'sk_live_test',
        ];
    }

    public function test_xflow_is_registered_as_pix_acquirer(): void
    {
        $this->assertTrue(GatewayRegistry::isAllowedAcquirer('xflow'));
        $def = GatewayRegistry::get('xflow');
        $this->assertNotNull($def);
        $this->assertContains('pix', $def['methods'] ?? []);
        $this->assertNotContains('card', $def['methods'] ?? []);
        $this->assertSame('images/gateways/xflow_logo1.svg', $def['image'] ?? null);
        $this->assertInstanceOf(XflowDriver::class, GatewayRegistry::driver('xflow'));
        $this->assertContains('xflow', config('gateways.default_order.pix'));
        $this->assertTrue(GatewayApiCredentials::isReadyForGateway('xflow', $this->credentials()));
        $this->assertFalse(GatewayApiCredentials::isReadyForGateway('xflow', ['public_key' => 'pk_live_x']));
    }

    public function test_webhook_url_is_public(): void
    {
        config([
            'app.url' => 'http://localhost:8085',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/xflow',
            GatewayWebhookUrl::forGateway('xflow')
        );
        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/xflow/payout',
            GatewayWebhookUrl::forGateway('xflow.payout')
        );
        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/xflow/disputes',
            GatewayWebhookUrl::forGateway('xflow.disputes')
        );
    }

    public function test_test_connection_uses_company_endpoint(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/company' => Http::response([
                'id' => 'co_1',
                'legal_name' => 'Xflow LTDA',
                'status' => 'active',
            ], 200),
        ]);

        $this->assertTrue((new XflowDriver)->testConnection($this->credentials()));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://app.xflowpayments.com/api/v1/company'
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_create_pix_sends_cents_and_maps_qr(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges' => Http::response([
                'id' => 'clx_charge_1',
                'livemode' => true,
                'status' => 'pending',
                'amountCents' => 1990,
                'external_reference' => '42',
                'pix' => [
                    'copyPaste' => '00020101br.gov.bcb.pix...',
                    'qrCodeBase64' => 'data:image/png;base64,iVBORw0',
                ],
            ], 201),
        ]);

        $result = (new XflowDriver)->createPixPayment(
            $this->credentials(),
            19.90,
            ['name' => 'Cliente Exemplo', 'document' => '123.456.789-09', 'email' => 'c@ex.com'],
            '42',
            'https://pay.exemplo.com/webhooks/gateways/xflow'
        );

        $this->assertSame('clx_charge_1', $result['transaction_id']);
        $this->assertSame('00020101br.gov.bcb.pix...', $result['copy_paste']);
        $this->assertSame('data:image/png;base64,iVBORw0', $result['qrcode']);
        $this->assertTrue($result['metadata']['xflow_livemode'] ?? false);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://app.xflowpayments.com/api/v1/charges' || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return ($body['amountCents'] ?? null) === 1990
                && ($body['external_reference'] ?? null) === '42'
                && ($body['customer']['document'] ?? null) === '12345678909'
                && $request->hasHeader('Idempotency-Key')
                && $request->header('Idempotency-Key')[0] === 'order-42';
        });
    }

    public function test_get_transaction_status_maps_paid_and_refunded(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges/clx_paid' => Http::response([
                'id' => 'clx_paid',
                'status' => 'paid',
            ], 200),
            'app.xflowpayments.com/api/v1/charges/clx_ref' => Http::response([
                'id' => 'clx_ref',
                'status' => 'refunded',
            ], 200),
            'app.xflowpayments.com/api/v1/charges/clx_block' => Http::response([
                'id' => 'clx_block',
                'status' => 'under_review',
            ], 200),
        ]);

        $driver = new XflowDriver;
        $this->assertSame('paid', $driver->getTransactionStatus('clx_paid', $this->credentials()));
        $this->assertSame('cancelled', $driver->getTransactionStatus('clx_ref', $this->credentials()));
        $this->assertSame('pending', $driver->getTransactionStatus('clx_block', $this->credentials()));
    }

    public function test_refund_posts_to_charge_and_maps_pending(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges/clx_1/refund' => Http::response([
                'id' => 'rf_1',
                'chargeId' => 'clx_1',
                'livemode' => true,
                'status' => 'pending',
                'amountCents' => 1990,
                'reason' => 'Estorno pedido #42',
            ], 202),
        ]);

        $result = (new XflowDriver)->refundTransaction($this->credentials(), 'clx_1', 19.90, '42');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['pending'] ?? false);
        $this->assertSame('rf_1', $result['refund_id'] ?? null);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://app.xflowpayments.com/api/v1/charges/clx_1/refund' || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return ($body['reason'] ?? null) === 'Estorno pedido #42'
                && $request->header('Idempotency-Key')[0] === 'refund-order-42';
        });
    }

    public function test_refund_maps_completed_and_window_expired(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges/clx_ok/refund' => Http::response([
                'id' => 'rf_ok',
                'chargeId' => 'clx_ok',
                'status' => 'completed',
                'amountCents' => 1000,
            ], 200),
            'app.xflowpayments.com/api/v1/charges/clx_old/refund' => Http::response([
                'error' => 'refund_window_expired',
                'message' => 'Refund window expired',
            ], 422),
        ]);

        $driver = new XflowDriver;
        $ok = $driver->refundTransaction($this->credentials(), 'clx_ok', 10, '7');
        $this->assertTrue($ok['success']);
        $this->assertFalse($ok['pending'] ?? true);

        $expired = $driver->refundTransaction($this->credentials(), 'clx_old', 10, '8');
        $this->assertFalse($expired['success']);
        $this->assertSame('refund_window_expired', $expired['error_code'] ?? null);
        $this->assertStringContainsString('90 dias', $expired['message'] ?? '');
    }

    public function test_get_refund_status_reads_charge_refund(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/charges/clx_rf' => Http::response([
                'id' => 'clx_rf',
                'status' => 'paid',
                'refund' => [
                    'id' => 'rf_1',
                    'status' => 'failed',
                    'failureReason' => 'PSP recusou',
                ],
            ], 200),
        ]);

        $this->assertSame('failed', (new XflowDriver)->getRefundStatus('clx_rf', $this->credentials()));
    }

    public function test_fetch_account_balance_returns_cents_payload(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/balance' => Http::response([
                'available' => 12345,
                'reserved' => 200,
                'currency' => 'BRL',
            ], 200),
        ]);

        $payload = (new XflowDriver)->fetchAccountBalance($this->credentials());
        $this->assertSame(12345, $payload['available']);
        $this->assertSame(123.45, app(AcquirerWalletBalanceService::class)->parseXflowAvailable($payload));
    }

    public function test_create_transfer_sends_cents_and_pix_key_type(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/transfers' => Http::response([
                'data' => [
                    'id' => 'tr_1',
                    'status' => 'processing',
                    'amount' => 1800,
                    'pending_approval' => false,
                ],
            ], 201),
        ]);

        $result = (new XflowDriver)->createTransfer(
            $this->credentials(),
            1800,
            'destino@pix.com',
            'email',
            'saque-99',
            'Saque #99'
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('tr_1', $result['transaction_id']);
        $this->assertFalse($result['pending_approval'] ?? true);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://app.xflowpayments.com/api/v1/transfers' || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return ($body['amount'] ?? null) === 1800
                && ($body['pix_key'] ?? null) === 'destino@pix.com'
                && ($body['pix_key_type'] ?? null) === 'EMAIL'
                && $request->header('Idempotency-Key')[0] === 'saque-99';
        });
    }

    public function test_get_transfer_status_maps_completed(): void
    {
        Http::fake([
            'app.xflowpayments.com/api/v1/transfers/tr_paid' => Http::response([
                'data' => ['id' => 'tr_paid', 'status' => 'completed'],
            ], 200),
            'app.xflowpayments.com/api/v1/transfers/tr_fail' => Http::response([
                'data' => ['id' => 'tr_fail', 'status' => 'failed'],
            ], 200),
        ]);

        $driver = new XflowDriver;
        $this->assertSame('paid', $driver->getTransferStatus('tr_paid', $this->credentials()));
        $this->assertSame('failed', $driver->getTransferStatus('tr_fail', $this->credentials()));
    }

    public function test_webhook_bootstrap_creates_endpoint_and_stores_secret(): void
    {
        config(['getfy.webhook_public_url' => 'https://pay.exemplo.com']);

        Http::fake([
            'app.xflowpayments.com/api/v1/webhooks' => Http::sequence()
                ->push(['data' => []], 200)
                ->push([
                    'id' => 'wh_1',
                    'url' => 'https://pay.exemplo.com/webhooks/gateways/xflow',
                    'secret' => 'whsec_once',
                    'events' => ['transaction.paid', 'transaction.refunded', 'withdrawal.processing', 'withdrawal.completed', 'withdrawal.failed', 'dispute.opened', 'dispute.accepted', 'dispute.rejected'],
                ], 201),
        ]);

        $boot = app(XflowWebhookBootstrapService::class)->bootstrap($this->credentials());

        $this->assertSame('wh_1', $boot['credentials']['webhook_endpoint_id'] ?? null);
        $this->assertSame('whsec_once', $boot['credentials']['webhook_secret'] ?? null);
        $this->assertNull($boot['warning']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/webhooks')) {
                return false;
            }
            $body = $request->data();
            $events = $body['events'] ?? [];

            return in_array('transaction.paid', $events, true)
                && in_array('transaction.refunded', $events, true)
                && in_array('transaction.refund_failed', $events, true)
                && in_array('withdrawal.completed', $events, true)
                && in_array('dispute.opened', $events, true);
        });
    }

    public function test_card_and_boleto_are_unsupported(): void
    {
        $driver = new XflowDriver;
        $this->expectException(\RuntimeException::class);
        $driver->createCardPayment($this->credentials(), 10, ['name' => 'A', 'document' => '1', 'email' => 'a@b.c'], '1', ['payment_token' => 'tok']);
    }
}
