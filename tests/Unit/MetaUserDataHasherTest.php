<?php

namespace Tests\Unit;

use App\Services\Meta\MetaUserDataHasher;
use Tests\TestCase;

class MetaUserDataHasherTest extends TestCase
{
    public function test_email_is_lowercased_and_hashed(): void
    {
        $hasher = new MetaUserDataHasher;
        $a = $hasher->hashEmail('Test@Example.COM');
        $b = $hasher->hashEmail('test@example.com');

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a ?? ''));
    }

    public function test_phone_strips_non_digits_before_hash(): void
    {
        $hasher = new MetaUserDataHasher;
        $a = $hasher->hashPhone('(11) 98888-7777');
        $b = $hasher->hashPhone('11988887777');

        $this->assertSame($a, $b);
    }

    public function test_phone_adds_brazil_country_code_without_duplicating_it(): void
    {
        $hasher = new MetaUserDataHasher;

        $this->assertSame('5511988887777', $hasher->normalizePhone('(11) 98888-7777'));
        $this->assertSame('5511988887777', $hasher->normalizePhone('5511988887777'));
        $this->assertSame(
            $hasher->hashPhone('11988887777'),
            $hasher->hashPhone('+55 (11) 98888-7777')
        );
    }

    public function test_city_and_state_match_meta_normalization(): void
    {
        $hasher = new MetaUserDataHasher;

        $this->assertSame($hasher->hashCity('São Paulo'), $hasher->hashCity('sao paulo'));
        $this->assertSame($hasher->hashState('São Paulo'), $hasher->hashState('SP'));
        $this->assertSame('sp', $hasher->normalizeState('São Paulo'));
    }

    public function test_cpf_hash_uses_digits_only(): void
    {
        $hasher = new MetaUserDataHasher;
        $a = $hasher->hashCpf('390.533.447-05');
        $b = $hasher->hashCpf('39053344705');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertNull($hasher->hashCpf('123'));
    }

    public function test_country_uses_iso2(): void
    {
        $hasher = new MetaUserDataHasher;
        $a = $hasher->hashCountry('BR');
        $b = $hasher->hashCountry('br');

        $this->assertSame($a, $b);
    }
}
