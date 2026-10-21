<?php

namespace Tests\Unit;

use App\Support\MetaPhoneNormalizer;
use Tests\TestCase;

class MetaPhoneNormalizerTest extends TestCase
{
    public function test_accepts_complete_brazilian_numbers(): void
    {
        $this->assertSame('5511988887777', MetaPhoneNormalizer::normalize('(11) 98888-7777'));
        $this->assertSame('5511988887777', MetaPhoneNormalizer::normalize('11988887777'));
        $this->assertSame('5511988887777', MetaPhoneNormalizer::normalize('5511988887777'));
        $this->assertSame('5511988887777', MetaPhoneNormalizer::normalize('+55 11 98888-7777'));
        $this->assertSame('551133334444', MetaPhoneNormalizer::normalize('1133334444'));
        $this->assertSame('551133334444', MetaPhoneNormalizer::normalize('551133334444'));
    }

    public function test_accepts_international_e164(): void
    {
        $this->assertSame('351912345678', MetaPhoneNormalizer::normalize('+351 912 345 678'));
        $this->assertSame('351912345678', MetaPhoneNormalizer::normalize('00351912345678'));
        $this->assertSame('14155552671', MetaPhoneNormalizer::normalize('+1 415 555 2671'));
        $this->assertSame('14155552671', MetaPhoneNormalizer::normalize('14155552671'));
        $this->assertSame('5491112345678', MetaPhoneNormalizer::normalize('5491112345678'));
    }

    public function test_rejects_incomplete_or_invalid_length(): void
    {
        $this->assertNull(MetaPhoneNormalizer::normalize(''));
        $this->assertNull(MetaPhoneNormalizer::normalize('123'));
        $this->assertNull(MetaPhoneNormalizer::normalize('55'));
        $this->assertNull(MetaPhoneNormalizer::normalize('5511'));
        $this->assertNull(MetaPhoneNormalizer::normalize('5511988'));
        $this->assertNull(MetaPhoneNormalizer::normalize('5511999999'));
        $this->assertNull(MetaPhoneNormalizer::normalize('55119999999'));
        $this->assertNull(MetaPhoneNormalizer::normalize('99999999'));
        $this->assertNull(MetaPhoneNormalizer::normalize('+55 11 9888'));
        $this->assertNull(MetaPhoneNormalizer::normalize('+1234567890123456'));
    }
}
