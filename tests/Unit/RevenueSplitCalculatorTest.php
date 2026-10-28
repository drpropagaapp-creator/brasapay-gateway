<?php

namespace Tests\Unit;

use App\Services\Coproduction\RevenueSplitCalculator;
use PHPUnit\Framework\TestCase;

class RevenueSplitCalculatorTest extends TestCase
{
    private function base(int $cents, array $coproducers, ?string $productId = 'p1'): array
    {
        return ['product_id' => $productId, 'cents' => $cents, 'coproducers' => $coproducers];
    }

    private function cop(int $tenant, int $bp, int $id): array
    {
        return ['tenant_id' => $tenant, 'basis_points' => $bp, 'product_coproducer_id' => $id];
    }

    private function byRole(array $result): array
    {
        $out = [];
        foreach ($result['slices'] as $s) {
            $out[$s['role'].':'.$s['tenant_id']] = $s['gross_cents'];
        }

        return $out;
    }

    public function test_cenario_a_100_reais_com_20_e_10_por_cento(): void
    {
        $r = RevenueSplitCalculator::split(1, [$this->base(10000, [$this->cop(2, 2000, 11), $this->cop(3, 1000, 12)])]);

        $this->assertSame(['seller:1' => 7000, 'coproducer:2' => 2000, 'coproducer:3' => 1000], $this->byRole($r));
        $this->assertSame(10000, $r['total_cents']);
    }

    public function test_cenario_b_297_reais_com_25_e_15_por_cento(): void
    {
        $r = RevenueSplitCalculator::split(1, [$this->base(29700, [$this->cop(2, 2500, 11), $this->cop(3, 1500, 12)])]);

        $this->assertSame(['seller:1' => 17820, 'coproducer:2' => 7425, 'coproducer:3' => 4455], $this->byRole($r));
    }

    public function test_cenario_c_um_centavo_fica_com_o_produtor(): void
    {
        $r = RevenueSplitCalculator::split(1, [$this->base(1, [$this->cop(2, 3333, 11), $this->cop(3, 3333, 12)])]);

        $this->assertSame(['seller:1' => 1], $this->byRole($r));
        $this->assertSame(1, array_sum(array_column($r['slices'], 'gross_cents')));
    }

    public function test_cenario_d_afiliado_sai_da_parte_do_produtor(): void
    {
        $r = RevenueSplitCalculator::split(
            1,
            [$this->base(10000, [$this->cop(2, 2000, 11)])],
            ['tenant_id' => 9, 'cents' => 3000, 'product_affiliate_enrollment_id' => 5, 'basis_points' => 3000]
        );

        $this->assertSame(['seller:1' => 5000, 'coproducer:2' => 2000, 'affiliate:9' => 3000], $this->byRole($r));
        $this->assertSame([], $r['issues']);
    }

    public function test_afiliado_acima_do_residuo_e_limitado_sem_criar_dinheiro(): void
    {
        $r = RevenueSplitCalculator::split(
            1,
            [$this->base(10000, [$this->cop(2, 6000, 11)])],
            ['tenant_id' => 9, 'cents' => 5000, 'product_affiliate_enrollment_id' => 5, 'basis_points' => 5000]
        );

        $this->assertSame(['seller:1' => 0, 'coproducer:2' => 6000, 'affiliate:9' => 4000], $this->byRole($r));
        $this->assertSame('affiliate_capped', $r['issues'][0]['type']);
    }

    public function test_cenario_f_coprodutor_do_principal_nao_ganha_no_bump_de_outro_produto(): void
    {
        $r = RevenueSplitCalculator::split(1, [
            $this->base(10000, [$this->cop(2, 2000, 11)], 'principal'),
            $this->base(3000, [], 'bump'),
        ]);

        $this->assertSame(['seller:1' => 11000, 'coproducer:2' => 2000], $this->byRole($r));
    }

    public function test_coprodutores_acima_de_100_por_cento_sao_ignorados_e_registrados(): void
    {
        $r = RevenueSplitCalculator::split(1, [$this->base(10000, [$this->cop(2, 6000, 11), $this->cop(3, 5000, 12)])]);

        $this->assertSame(['seller:1' => 10000], $this->byRole($r));
        $this->assertSame('coproducers_over_100_percent', $r['issues'][0]['type']);
    }

    public function test_fatias_do_mesmo_tenant_sao_somadas(): void
    {
        $r = RevenueSplitCalculator::split(1, [$this->base(10000, [$this->cop(2, 1000, 11), $this->cop(2, 1000, 12)])]);

        $this->assertSame(['seller:1' => 8000, 'coproducer:2' => 2000], $this->byRole($r));
    }
}
