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

    public function test_rejects_invalid_phones(): void
    {
        $this->assertNull(WhatsappPhoneNormalizer::normalize(''));
        $this->assertNull(WhatsappPhoneNormalizer::normalize('123'));
        $this->assertNull(WhatsappPhoneNormalizer::normalize('+1234567890123456'));
    }
}
