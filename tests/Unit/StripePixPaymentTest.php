<?php

namespace Tests\Unit;

use App\Gateways\Stripe\StripeDriver;
use Stripe\PaymentIntent;
use Tests\TestCase;

class StripePixPaymentTest extends TestCase
{
    public function test_pix_requires_a_cpf(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CPF');

        (new StripeDriver())->createPixPayment(
            ['secret_key' => 'sk_test'],
            10.00,
            ['name' => 'Ana Silva', 'email' => 'ana@example.com', 'document' => ''],
            '1',
            'https://example.test/webhook'
        );
    }

    public function test_pix_charge_reads_copy_paste_and_qr_image(): void
    {
        $intent = PaymentIntent::constructFrom([
            'id' => 'pi_pix_1',
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'pix_display_qr_code',
                'pix_display_qr_code' => [
                    'data' => '00020126PIX',
                    'image_url_png' => 'https://files.stripe.com/qr.png',
                ],
            ],
        ]);

        $result = (new StripeDriver())->pixChargeFromPaymentIntent($intent);

        $this->assertSame('pi_pix_1', $result['transaction_id']);
        $this->assertSame('00020126PIX', $result['copy_paste']);
        $this->assertSame('https://files.stripe.com/qr.png', $result['qrcode']);
    }
}
