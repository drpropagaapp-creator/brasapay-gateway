<?php

namespace App\Services\Coproduction;

use LogicException;

/**
 * Divisão do bruto de um pedido entre produtor, co-produtores e afiliado —
 * só com inteiros (centavos e pontos-base), sem banco de dados.
 *
 * Regras:
 * - Cada base (produto vendido no pedido) paga apenas os co-produtores DAQUELE
 *   produto: a comissão de um produto nunca incide sobre o item de outro.
 * - Co-produtor recebe floor(base × pontos-base / 10000); o produtor fica com
 *   o resíduo, inclusive os centavos de arredondamento (determinístico e nunca
 *   distribui mais do que existe).
 * - O afiliado sai da parte do produtor. Se não couber (configuração antiga
 *   inválida), é limitado ao que resta e o problema é devolvido em `issues` —
 *   nunca há fatia negativa nem soma acima do total.
 * - Co-produtores de uma base que somam mais de 100% são ignorados nessa base
 *   (comportamento anterior), também registrado em `issues`.
 * - Fatias do mesmo tenant são somadas: a carteira credita uma vez por tenant.
 */
final class RevenueSplitCalculator
{
    public const VERSION = 2;

    private const FULL_BASIS_POINTS = 10000;

    /**
     * @param  list<array{product_id: string|null, cents: int, coproducers: list<array{tenant_id: int, basis_points: int, product_coproducer_id: int}>}>  $bases
     * @param  array{tenant_id: int, cents: int, product_affiliate_enrollment_id: int, basis_points: int}|null  $affiliate
     * @return array{version: int, total_cents: int, slices: list<array<string, mixed>>, issues: list<array<string, mixed>>}
     */
    public static function split(int $sellerTenantId, array $bases, ?array $affiliate = null): array
    {
        $issues = [];
        $totalCents = 0;
        $coproducerSlices = [];

        foreach ($bases as $base) {
            $baseCents = max(0, (int) $base['cents']);
            $totalCents += $baseCents;
            $coproducers = $base['coproducers'] ?? [];
            if ($baseCents === 0 || $coproducers === []) {
                continue;
            }

            $sumBp = array_sum(array_map(fn (array $c) => max(0, (int) $c['basis_points']), $coproducers));
            if ($sumBp > self::FULL_BASIS_POINTS) {
                $issues[] = [
                    'type' => 'coproducers_over_100_percent',
                    'product_id' => $base['product_id'],
                    'basis_points' => $sumBp,
                ];

                continue;
            }

            foreach ($coproducers as $c) {
                $bp = max(0, (int) $c['basis_points']);
                $cents = intdiv($baseCents * $bp, self::FULL_BASIS_POINTS);
                if ($cents <= 0 || (int) $c['tenant_id'] < 1) {
                    continue;
                }
                $coproducerSlices[] = [
                    'role' => 'coproducer',
                    'tenant_id' => (int) $c['tenant_id'],
                    'gross_cents' => $cents,
                    'product_coproducer_id' => (int) $c['product_coproducer_id'],
                    'basis_points' => $bp,
                    'base_product_id' => $base['product_id'],
                    'base_cents' => $baseCents,
                ];
            }
        }

        $coproducerTotal = array_sum(array_column($coproducerSlices, 'gross_cents'));
        $sellerCents = $totalCents - $coproducerTotal;

        $affiliateSlice = null;
        if ($affiliate !== null && (int) $affiliate['cents'] > 0 && (int) $affiliate['tenant_id'] > 0) {
            $requested = (int) $affiliate['cents'];
            $applied = min($requested, $sellerCents);
            if ($applied < $requested) {
                $issues[] = [
                    'type' => 'affiliate_capped',
                    'requested_cents' => $requested,
                    'applied_cents' => $applied,
                ];
            }
            if ($applied > 0) {
                $sellerCents -= $applied;
                $affiliateSlice = [
                    'role' => 'affiliate',
                    'tenant_id' => (int) $affiliate['tenant_id'],
                    'gross_cents' => $applied,
                    'product_affiliate_enrollment_id' => (int) $affiliate['product_affiliate_enrollment_id'],
                    'basis_points' => (int) ($affiliate['basis_points'] ?? 0),
                ];
            }
        }

        $raw = array_merge(
            [['role' => 'seller', 'tenant_id' => $sellerTenantId, 'gross_cents' => $sellerCents]],
            $coproducerSlices,
            $affiliateSlice !== null ? [$affiliateSlice] : []
        );

        $slices = self::mergeByTenant($raw, $issues);

        $sum = array_sum(array_column($slices, 'gross_cents'));
        if ($sum !== $totalCents) {
            throw new LogicException("Split não fecha: {$sum} != {$totalCents} centavos.");
        }
        foreach ($slices as $s) {
            if ($s['gross_cents'] < 0) {
                throw new LogicException('Split gerou fatia negativa.');
            }
        }

        return [
            'version' => self::VERSION,
            'total_cents' => $totalCents,
            'slices' => $slices,
            'issues' => $issues,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private static function mergeByTenant(array $raw, array &$issues): array
    {
        $byTenant = [];
        foreach ($raw as $slice) {
            $tenant = (int) $slice['tenant_id'];
            if (! isset($byTenant[$tenant])) {
                $slice['product_coproducer_ids'] = isset($slice['product_coproducer_id']) ? [(int) $slice['product_coproducer_id']] : [];
                $byTenant[$tenant] = $slice;

                continue;
            }

            $byTenant[$tenant]['gross_cents'] += (int) $slice['gross_cents'];
            if (isset($slice['product_coproducer_id'])) {
                $byTenant[$tenant]['product_coproducer_ids'][] = (int) $slice['product_coproducer_id'];
            }
            $issues[] = [
                'type' => 'merged_same_tenant',
                'tenant_id' => $tenant,
                'role' => $slice['role'],
            ];
        }

        return array_values($byTenant);
    }
}
