<?php

namespace Tests\Unit;

use App\Support\WhatsappPhoneNormalizer;
use Tests\TestCase;

class WhatsappPhoneNormalizerTest extends TestCase
{
    public function test_normalizes_brazilian_local_and_with_country_code(): void
    {
        $this->assertSame('5511999887766', WhatsappPhoneNormalizer::normalize('(11) 99988-7766'));
        $this->assertSame('5511999887766', WhatsappPhoneNormalizer::normalize('5511999887766'));
        $this->assertSame('5511999887766', WhatsappPhoneNormalizer::normalize('+55 11 99988-7766'));
    }

    public function test_normalizes_international_numbers(): void
    {
        $this->assertSame('351912345678', WhatsappPhoneNormalizer::normalize('+351 912 345 678'));
        $this->assertSame('351912345678', WhatsappPhoneNormalizer::normalize('00351912345678'));
        $this->assertSame('14155552671', WhatsappPhoneNormalizer::normalize('+1 415 555 2671'));
        $this->assertSame('5491112345678', WhatsappPhoneNormalizer::normalize('5491112345678'));
    }

    public function test_international_digits_saved_without_plus_are_not_treated_as_brazilian(): void
    {
        // Checkout gravava DDI + número sem "+": EUA tem 11 dígitos, mas o 3º nunca é 9.
        $this->assertSame('18575551233', WhatsappPhoneNormalizer::normalize('18575551233'));
        $this->assertSame('33612345678', WhatsappPhoneNormalizer::normalize('33612345678'));
        // Brasil sem DDI continua ganhando 55 (celular com 9, fixo com 3º dígito 2–8).
        $this->assertSame('5518991234567', WhatsappPhoneNormalizer::normalize('18991234567'));
        $this->assertSame('551832345678', WhatsappPhoneNormalizer::normalize('1832345678'));
    }

    public function test_rejects_invalid_phones(): void
    {
        $this->assertNull(WhatsappPhoneNormalizer::normalize(''));
        $this->assertNull(WhatsappPhoneNormalizer::normalize('123'));
        $this->assertNull(WhatsappPhoneNormalizer::normalize('+1234567890123456'));
    }
}
