<?php

namespace Tests\Feature;

use App\Gateways\Cakto\CaktoDriver;
use App\Http\Controllers\Webhooks\CaktoWebhookController;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaktoGatewayTest extends TestCase
{
    private array $creds = [
        'client_id' => 'cid_test',
        'client_secret' => 'secret_test',
        'product_id' => 'prod-uuid-1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function cardPayload(array $over = []): array
    {
        return ['payment_token' => json_encode(array_merge([
            'card_token' => 'tok_abc',
            'antifraud_reference' => 'af-ref-1',
            'fingerprint' => 'bp_device_1',
            'installments' => 1,
        ], $over))];
    }

    private function consumer(): array
    {
        return ['name' => 'Maria Souza', 'email' => 'maria@test.com', 'document' => '52998224725', 'phone' => '+5511999998888'];
    }

    public function test_cartao_aprovado_cria_oferta_do_valor_e_reaproveita(): void
    {
        Http::fake([
            'api.cakto.com.br/public_api/token/' => Http::response(['access_token' => 'tkn', 'expires_in' => 3600]),
            'api.cakto.com.br/public_api/offers/' => Http::response(['id' => 'OFF123', 'price' => 97.5], 201),
            'api.cakto.com.br/public_api/payments/' => Http::response(['id' => 'ord-uuid-1', 'refId' => 'ABC', 'status' => 'paid', 'amount' => '97.50'], 201),
        ]);

        $driver = new CaktoDriver;
        $r = $driver->createCardPayment($this->creds, 97.50, $this->consumer(), '501', $this->cardPayload());

        $this->assertSame('ord-uuid-1', $r['transaction_id']);
        $this->assertSame('paid', $r['status']);
        $this->assertSame('OFF123', DB::table('cakto_offers')->where('amount_cents', 9750)->value('offer_id'));

        Http::assertSent(function (HttpRequest $req) {
            if (! str_ends_with($req->url(), '/public_api/payments/')) {
                return false;
            }
            $b = $req->data();

            return $req->hasHeader('X-Idempotency-Key')
                && $b['paymentMethod'] === 'credit_card'
                && $b['items'][0]['offerId'] === 'OFF123'
                && $b['card']['token'] === 'tok_abc'
                && $b['antifraud_profiling_attempt_reference'] === 'af-ref-1'
                && $b['customer']['phone'] === '5511999998888'
                && $b['customer']['docType'] === 'cpf'
                && $b['customer']['fingerprint'] === 'bp_device_1';
        });

        // Segunda venda do mesmo valor: não cria outra oferta.
        $driver->createCardPayment($this->creds, 97.50, $this->consumer(), '502', $this->cardPayload(['card_token' => 'tok_2']));
        Http::assertSentCount(4); // token + oferta + 2 cobranças
    }

    public function test_cartao_recusado_vira_refused(): void
    {
        Http::fake([
            'api.cakto.com.br/public_api/token/' => Http::response(['access_token' => 'tkn', 'expires_in' => 3600]),
            'api.cakto.com.br/public_api/offers/' => Http::response(['id' => 'OFF9'], 201),
            'api.cakto.com.br/public_api/payments/' => Http::response(['id' => 'ord-2', 'status' => 'declined'], 201),
        ]);

        $r = (new CaktoDriver)->createCardPayment($this->creds, 20.0, $this->consumer(), '600', $this->cardPayload());
        $this->assertSame('refused', $r['status']);
    }

    public function test_sem_antifraude_ou_valor_baixo_nao_cobra(): void
    {
        Http::fake();
        $driver = new CaktoDriver;

        try {
            $driver->createCardPayment($this->creds, 50.0, $this->consumer(), '700', $this->cardPayload(['antifraud_reference' => '']));
            $this->fail('Devia exigir antifraude.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('antifraude', $e->getMessage());
        }

        Http::fake(['api.cakto.com.br/public_api/token/' => Http::response(['access_token' => 'tkn', 'expires_in' => 3600])]);
        $this->expectExceptionMessage('R$ 5,00');
        $driver->createCardPayment($this->creds, 4.99, $this->consumer(), '701', $this->cardPayload());
    }

    public function test_telefone_vira_e164(): void
    {
        $this->assertSame('5511999998888', CaktoDriver::normalizePhone('(11) 99999-8888'));
        $this->assertSame('18575551233', CaktoDriver::normalizePhone('+1 857 555 1233'));
        $this->assertSame('351912345678', CaktoDriver::normalizePhone('+351 912 345 678'));
    }

    public function test_assinatura_do_webhook(): void
    {
        $body = '{"event":"purchase_approved","data":{"id":"x"}}';
        $ts = '1763400000';
        $sig = 'v1='.hash_hmac('sha256', $ts.'.'.$body, 'whsec');

        $this->assertTrue(CaktoWebhookController::verifySignature($body, $ts, $sig, 'whsec', 1763400010));
        $this->assertFalse(CaktoWebhookController::verifySignature($body, $ts, $sig, 'outro', 1763400010));
        $this->assertFalse(CaktoWebhookController::verifySignature($body.' ', $ts, $sig, 'whsec', 1763400010));
        $this->assertFalse(CaktoWebhookController::verifySignature($body, $ts, $sig, 'whsec', 1763400000 + 3600), 'Entrega antiga é recusada.');
    }

    public function test_status_do_pedido(): void
    {
        $this->assertSame('paid', CaktoDriver::mapOrderStatus('paid'));
        $this->assertSame('pending', CaktoDriver::mapOrderStatus('waiting_payment'));
        $this->assertSame('cancelled', CaktoDriver::mapOrderStatus('refunded'));
        $this->assertNull(CaktoDriver::mapOrderStatus('refund_requested'));
    }
}
