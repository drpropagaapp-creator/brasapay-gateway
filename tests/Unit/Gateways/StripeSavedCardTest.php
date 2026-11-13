<?php

namespace Tests\Unit\Gateways;

use App\Gateways\Stripe\StripeDriver;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Cartão salvo na Stripe: a primeira cobrança vincula o cartão a um cliente (Customer) com
 * setup_future_usage; as seguintes vão com o mesmo cliente e off_session. Sem o cliente a Stripe
 * recusa o reuso — era por isso que a renovação automática não funcionava.
 */
class StripeSavedCardTest extends TestCase
{
    /** @var list<array{method: string, url: string, params: array}> */
    private array $calls = [];

    /** @var array<string, array{0: int, 1: array}> resposta por trecho da URL */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        ApiRequestor::setHttpClient(new class($test) implements ClientInterface
        {
            public function __construct(private StripeSavedCardTest $test) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return $this->test->fakeStripe((string) $method, (string) $absUrl, is_array($params) ? $params : []);
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** @return array{0: string, 1: int, 2: array} */
    public function fakeStripe(string $method, string $url, array $params): array
    {
        $this->calls[] = ['method' => strtoupper($method), 'url' => $url, 'params' => $params];
        foreach ($this->responses as $fragment => [$code, $body]) {
            if (str_contains($url, $fragment)) {
                return [json_encode($body), $code, []];
            }
        }

        return [json_encode(['error' => ['message' => 'sem resposta para '.$url]]), 500, []];
    }

    private function stripeCall(string $fragment): ?array
    {
        foreach ($this->calls as $c) {
            if (str_contains($c['url'], $fragment)) {
                return $c;
            }
        }

        return null;
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 'true';
    }

    public function test_primeira_cobranca_vincula_cartao_a_cliente_e_devolve_detalhes(): void
    {
        $this->responses = [
            '/v1/customers' => [200, ['id' => 'cus_123', 'object' => 'customer']],
            '/v1/payment_intents' => [200, ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']],
            '/v1/payment_methods/pm_abc' => [200, ['id' => 'pm_abc', 'object' => 'payment_method', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2031]]],
        ];

        $result = (new StripeDriver)->createCardPayment(
            ['secret_key' => 'sk_test_x'],
            97.0,
            ['name' => 'Ana', 'email' => 'ana@example.com', 'document' => '12345678909'],
            '55',
            ['payment_token' => 'pm_abc', 'currency' => 'brl', 'save' => true],
        );

        $this->assertSame('paid', $result['status']);
        $this->assertSame('cus_123', $result['saved_card']['customer_id']);
        $this->assertSame('pm_abc', $result['saved_card']['payment_method_id']);
        $this->assertSame('visa', $result['saved_card']['brand']);
        $this->assertSame('4242', $result['saved_card']['last_four']);
        $this->assertSame(12, $result['saved_card']['exp_month']);
        $this->assertSame(2031, $result['saved_card']['exp_year']);

        $pi = $this->stripeCall('/v1/payment_intents');
        $this->assertNotNull($pi);
        $this->assertSame('cus_123', $pi['params']['customer']);
        $this->assertSame('off_session', $pi['params']['setup_future_usage']);
        $this->assertSame('brl', $pi['params']['currency']);
        $this->assertSame(9700, (int) $pi['params']['amount']);
        $this->assertArrayNotHasKey('off_session', $pi['params']);
    }

    public function test_cobranca_seguinte_usa_cliente_e_off_session_sem_criar_cliente(): void
    {
        $this->responses = [
            '/v1/payment_intents' => [200, ['id' => 'pi_2', 'object' => 'payment_intent', 'status' => 'succeeded']],
        ];

        $result = (new StripeDriver)->createCardPayment(
            ['secret_key' => 'sk_test_x'],
            49.9,
            ['name' => 'Ana', 'email' => 'ana@example.com', 'document' => '12345678909'],
            '56',
            ['payment_token' => 'pm_abc', 'currency' => 'brl', 'customer_id' => 'cus_123', 'off_session' => true, 'return_url' => 'https://x.test/volta'],
        );

        $this->assertSame('paid', $result['status']);
        $this->assertArrayNotHasKey('saved_card', $result);
        $this->assertNull($this->stripeCall('/v1/customers'));
        $pi = $this->stripeCall('/v1/payment_intents');
        $this->assertSame('cus_123', $pi['params']['customer']);
        $this->assertTrue($this->truthy($pi['params']['off_session']));
        $this->assertArrayNotHasKey('setup_future_usage', $pi['params']);
        // Sem o cliente presente não há para onde redirecionar.
        $this->assertArrayNotHasKey('return_url', $pi['params']);
    }

    public function test_off_session_sem_cliente_nem_chama_a_stripe(): void
    {
        try {
            (new StripeDriver)->createCardPayment(
                ['secret_key' => 'sk_test_x'],
                49.9,
                ['name' => 'Ana', 'email' => 'ana@example.com'],
                '57',
                ['payment_token' => 'pm_abc', 'currency' => 'brl', 'off_session' => true],
            );
            $this->fail('deveria recusar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sem cliente vinculado', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    public function test_banco_pede_confirmacao_vira_mensagem_clara(): void
    {
        $this->responses = [
            '/v1/payment_intents' => [402, ['error' => ['type' => 'card_error', 'code' => 'authentication_required', 'message' => 'Authentication required']]],
        ];

        try {
            (new StripeDriver)->createCardPayment(
                ['secret_key' => 'sk_test_x'],
                49.9,
                ['name' => 'Ana', 'email' => 'ana@example.com'],
                '58',
                ['payment_token' => 'pm_abc', 'currency' => 'brl', 'customer_id' => 'cus_123', 'off_session' => true],
            );
            $this->fail('deveria lançar');
        } catch (\RuntimeException $e) {
            $this->assertSame(StripeDriver::OFF_SESSION_AUTH_MESSAGE, $e->getMessage());
        }
    }

    public function test_remover_cartao_desvincula_na_stripe(): void
    {
        $this->responses = [
            '/v1/payment_methods/pm_abc/detach' => [200, ['id' => 'pm_abc', 'object' => 'payment_method']],
        ];

        (new StripeDriver)->forgetSavedCard(['secret_key' => 'sk_test_x'], 'pm_abc');

        $detach = $this->stripeCall('/detach');
        $this->assertNotNull($detach);
        $this->assertSame('POST', $detach['method']);
    }
}
