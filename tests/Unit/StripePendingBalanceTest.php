<?php

namespace Tests\Unit;

use App\Services\Platform\AcquirerWalletBalanceService;
use PHPUnit\Framework\TestCase;

class StripePendingBalanceTest extends TestCase
{
    public function test_pending_is_summed_in_the_chosen_currency(): void
    {
        $service = (new \ReflectionClass(AcquirerWalletBalanceService::class))->newInstanceWithoutConstructor();

        $body = [
            'available' => [['amount' => -75, 'currency' => 'brl']],
            'pending' => [
                ['amount' => 15180, 'currency' => 'brl'],
                ['amount' => 9999, 'currency' => 'usd'],
            ],
        ];

        $this->assertSame(151.8, $service->parseStripePending($body, 'BRL'));
        $this->assertSame(99.99, $service->parseStripePending($body, 'usd'));
    }

    public function test_pending_is_null_when_response_has_no_pending_block(): void
    {
        $service = (new \ReflectionClass(AcquirerWalletBalanceService::class))->newInstanceWithoutConstructor();

        $this->assertNull($service->parseStripePending(['available' => [['amount' => 100, 'currency' => 'brl']]], 'BRL'));
    }
}
