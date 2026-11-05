<?php

namespace Tests\Feature;

use App\Services\Platform\PlatformCashReconciliation;
use Tests\TestCase;

class PlatformCashReconciliationTest extends TestCase
{
    public function test_cash_is_summed_from_ok_brl_acquirers_and_errors_are_flagged(): void
    {
        $rows = [
            ['status' => 'ok', 'currency' => 'BRL', 'available' => 455.44],
            ['status' => 'ok', 'currency' => 'BRL', 'available' => -0.75, 'pending' => 151.80],
            ['status' => 'ok', 'currency' => 'USD', 'available' => 500.0],
            ['status' => 'error', 'currency' => 'BRL', 'available' => null],
            ['status' => 'inactive', 'currency' => 'BRL', 'available' => null],
        ];

        $r = PlatformCashReconciliation::build($rows);

        $this->assertSame(454.69, $r['caixa_disponivel']);
        $this->assertSame(151.8, $r['caixa_a_liberar']);
        $this->assertSame(1, $r['adquirentes_com_erro']);
        $this->assertSame(1, $r['adquirentes_moeda_ignorada']);
        // sem carteiras/saques no banco de teste: sobra = caixa
        $this->assertSame($r['caixa_disponivel'] - $r['devido_agora'], $r['sobra_agora']);
    }
}
