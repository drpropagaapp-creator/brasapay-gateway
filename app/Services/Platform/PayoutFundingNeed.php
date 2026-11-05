<?php

namespace App\Services\Platform;

use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayAccountResolver;
use App\Services\MerchantWithdrawalService;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Payout\PlatformPayoutGateway;
use Illuminate\Support\Facades\Schema;

/**
 * Quanto a plataforma precisa ter na conta de saque (CajuPay etc.) para pagar os saques pendentes.
 *
 * O valor debitado do adquirente por saque é o líquido do seller mais a taxa de saque do adquirente
 * (o mesmo cálculo de GatewayPayoutEconomics::transferAmountBrlForApi). Nada é movimentado aqui:
 * é só um aviso para o admin abastecer a conta antes de aprovar.
 */
final class PayoutFundingNeed
{
    /**
     * @param  list<array<string, mixed>>  $acquirerWallets  linhas de AcquirerWalletBalanceService::list()
     * @return array{
     *     pending_count: int,
     *     net_total: float,
     *     fees_total: float,
     *     required_total: float,
     *     shortfall_total: float,
     *     covered: bool,
     *     balance_unavailable: bool,
     *     groups: list<array<string, mixed>>,
     *     items: array<int, array{required: float, status: string, missing: float}>
     * }
     */
    public static function compute(array $acquirerWallets): array
    {
        $empty = [
            'pending_count' => 0,
            'net_total' => 0.0,
            'fees_total' => 0.0,
            'required_total' => 0.0,
            'shortfall_total' => 0.0,
            'covered' => true,
            'balance_unavailable' => false,
            'groups' => [],
            'items' => [],
        ];

        if (! Schema::hasTable('withdrawals')) {
            return $empty;
        }

        $pending = Withdrawal::query()
            ->where('status', MerchantWithdrawalService::STATUS_PENDING)
            ->orderBy('id')
            ->get();
        if ($pending->isEmpty()) {
            return $empty;
        }

        $balances = self::balancesByKey($acquirerWallets);
        $activeSlug = (string) (PlatformPayoutGateway::activeSlug() ?? '');
        $resolver = app(CajuPayAccountResolver::class);

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        $items = [];
        $netTotal = 0.0;
        $feesTotal = 0.0;

        foreach ($pending as $w) {
            $slug = strtolower(trim((string) ($w->payout_provider ?? '')));
            if ($slug === '' || $slug === 'manual') {
                $slug = $activeSlug;
            }
            if ($slug === '') {
                continue;
            }

            $accountId = 0;
            $accountName = null;
            if ($slug === 'cajupay') {
                $meta = is_array($w->payout_meta) ? $w->payout_meta : [];
                $metaId = (int) ($meta['cajupay_account_id'] ?? 0);
                $account = $metaId > 0 ? $resolver->resolveById($metaId) : null;
                $account ??= $resolver->resolveForTenant((int) $w->tenant_id) ?? $resolver->defaultOrFirstConnected();
                $accountId = (int) ($account?->id ?? 0);
                $accountName = $account !== null && trim((string) $account->name) !== '' ? (string) $account->name : null;
            }

            $key = $slug === 'cajupay' ? 'cajupay:'.$accountId : $slug;

            $economics = GatewayPayoutEconomics::forWithdrawal($w);
            $net = round((float) ($w->net_amount ?? 0), 2);
            $required = GatewayPayoutEconomics::transferAmountBrlForApi(
                $net,
                (float) ($economics['admin_fee_payout_brl'] ?? 0),
                (float) ($economics['admin_fee_payout_percent'] ?? 0),
            );
            $fee = round($required - $net, 2);

            if (! isset($groups[$key])) {
                $balance = $balances[$key] ?? null;
                if ($balance === null && $slug === 'cajupay' && isset($balances['cajupay:*'])) {
                    $balance = $balances['cajupay:*'];
                }
                $groups[$key] = [
                    'key' => $key,
                    'slug' => $slug,
                    'conta' => $accountName,
                    'count' => 0,
                    'net' => 0.0,
                    'fees' => 0.0,
                    'required' => 0.0,
                    'balance' => $balance,
                    'running' => $balance,
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['net'] += $net;
            $groups[$key]['fees'] += $fee;
            $groups[$key]['required'] += $required;

            // Cobertura em ordem de pedido: o saldo vai cobrindo do mais antigo para o mais novo.
            $running = $groups[$key]['running'];
            if ($running === null) {
                $items[(int) $w->id] = ['required' => round($required, 2), 'status' => 'unknown', 'missing' => 0.0];
            } elseif ($running + 0.0001 >= $required) {
                $groups[$key]['running'] = round($running - $required, 2);
                $items[(int) $w->id] = ['required' => round($required, 2), 'status' => 'ok', 'missing' => 0.0];
            } else {
                $missing = round($required - max(0.0, (float) $running), 2);
                $items[(int) $w->id] = ['required' => round($required, 2), 'status' => 'short', 'missing' => $missing];
            }

            $netTotal += $net;
            $feesTotal += $fee;
        }

        $out = [];
        $requiredTotal = 0.0;
        $shortfallTotal = 0.0;
        $unavailable = false;
        foreach ($groups as $g) {
            $required = round((float) $g['required'], 2);
            $balance = $g['balance'] !== null ? round((float) $g['balance'], 2) : null;
            $shortfall = $balance !== null ? round(max(0.0, $required - $balance), 2) : null;
            if ($balance === null) {
                $unavailable = true;
            }
            $requiredTotal += $required;
            $shortfallTotal += (float) ($shortfall ?? 0);
            $out[] = [
                'key' => $g['key'],
                'slug' => $g['slug'],
                'conta' => $g['conta'],
                'count' => $g['count'],
                'net' => round((float) $g['net'], 2),
                'fees' => round((float) $g['fees'], 2),
                'required' => $required,
                'balance' => $balance,
                'shortfall' => $shortfall,
            ];
        }

        return [
            'pending_count' => count($items),
            'net_total' => round($netTotal, 2),
            'fees_total' => round($feesTotal, 2),
            'required_total' => round($requiredTotal, 2),
            'shortfall_total' => round($shortfallTotal, 2),
            'covered' => ! $unavailable && $shortfallTotal <= 0.0001,
            'balance_unavailable' => $unavailable,
            'groups' => $out,
            'items' => $items,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private static function balancesByKey(array $rows): array
    {
        $map = [];
        $cajuRows = 0;
        $cajuSingle = null;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'ok' || strtoupper((string) ($row['currency'] ?? 'BRL')) !== 'BRL') {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            $available = (float) ($row['available'] ?? 0);
            if ($slug === 'cajupay') {
                $cajuRows++;
                $cajuSingle = $available;
                $id = (string) ($row['id'] ?? '');
                if (preg_match('/^cajupay:account:(\d+)$/', $id, $m) === 1) {
                    $map['cajupay:'.$m[1]] = $available;
                } else {
                    $map['cajupay:0'] = $available;
                }

                continue;
            }
            $map[$slug] = $available;
        }
        // Uma única conta CajuPay conectada atende qualquer saque que não apontou conta específica.
        if ($cajuRows === 1 && $cajuSingle !== null) {
            $map['cajupay:*'] = $cajuSingle;
        }

        return $map;
    }
}
