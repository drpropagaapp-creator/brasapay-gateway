<?php

namespace Tests\Unit;

use App\Gateways\Stripe\StripeDriver;
use Stripe\PaymentIntent;
use Tests\TestCase;

class StripeBoletoPaymentTest extends TestCase
{
    public function test_stripe_config_exposes_boleto(): void
    {
        $this->assertContains('boleto', config('gateways.gateways.stripe.methods'));
        $this->assertContains('stripe', config('gateways.default_order.boleto'));
    }

    public function test_boleto_requires_a_tax_id(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CPF ou CNPJ');

        (new StripeDriver())->createBoletoPayment(
            ['secret_key' => 'sk_test'],
            20.00,
            [
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'document' => '',
                'address' => [
                    'zip_code' => '01310100',
                    'street_name' => 'Av Paulista',
                    'street_number' => '1000',
                    'city' => 'Sao Paulo',
                    'federal_unit' => 'SP',
                ],
            ],
            '1',
            'https://example.test/webhook'
        );
    }

    public function test_boleto_requires_a_complete_address(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('endereço');

        (new StripeDriver())->createBoletoPayment(
            ['secret_key' => 'sk_test'],
            20.00,
            [
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'document' => '39053344705',
            ],
            '1',
            'https://example.test/webhook'
        );
    }

    public function test_boleto_charge_reads_number_pdf_and_expiration(): void
    {
        $intent = PaymentIntent::constructFrom([
            'id' => 'pi_boleto_1',
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'boleto_display_details',
                'boleto_display_details' => [
                    'number' => '34191.09008 64435.250024 40163.390025 8 95870000001000',
                    'pdf' => 'https://payments.stripe.com/boleto/pdf/test',
                    'hosted_voucher_url' => 'https://payments.stripe.com/boleto/voucher/test',
                    'expires_at' => 1730419199,
                ],
            ],
        ]);

        $result = (new StripeDriver())->boletoChargeFromPaymentIntent($intent, 20.00);

        $this->assertSame('pi_boleto_1', $result['transaction_id']);
        $this->assertSame(20.00, $result['amount']);
        $this->assertSame('34191.09008 64435.250024 40163.390025 8 95870000001000', $result['barcode']);
        $this->assertSame('https://payments.stripe.com/boleto/pdf/test', $result['pdf_url']);
        $this->assertSame(date('Y-m-d', 1730419199), $result['expire_at']);
        $this->assertSame('pending', $result['status']);
    }

    public function test_boleto_charge_falls_back_to_hosted_voucher_url(): void
    {
        $intent = PaymentIntent::constructFrom([
            'id' => 'pi_boleto_2',
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'boleto_display_details',
                'boleto_display_details' => [
                    'number' => '34191.09008',
                    'hosted_voucher_url' => 'https://payments.stripe.com/boleto/voucher/fallback',
                    'expires_at' => '2026-10-31T23:59:59-03:00',
                ],
            ],
        ]);

        $result = (new StripeDriver())->boletoChargeFromPaymentIntent($intent, 15.50);

        $this->assertSame('https://payments.stripe.com/boleto/voucher/fallback', $result['pdf_url']);
        $this->assertSame('2026-10-31', $result['expire_at']);
    }
}
