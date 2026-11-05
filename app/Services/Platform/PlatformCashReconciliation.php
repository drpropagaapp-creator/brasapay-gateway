<?php

namespace App\Services\Platform;

use App\Models\TenantWallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliação de caixa do admin: o que a plataforma TEM nas adquirentes (ativo) contra o que DEVE aos sellers (passivo).
 *
 * - Caixa disponível = soma do saldo disponível das adquirentes consultadas (BRL).
 * - Devido aos sellers = saldo disponível das carteiras + saques já pedidos e ainda não pagos
 *   (o saque debita a carteira na solicitação, então não há dupla contagem).
 * - A liberar = valores retidos (adquirentes: ex. Stripe; sellers: vendas dentro do prazo D+N).
 *
 * Os valores de carteira e saque são lidos ao vivo (não do cache do dashboard) para fechar com o caixa.
 */
final class PlatformCashReconciliation
{
    /**
     * @param  list<array<string, mixed>>  $acquirerWallets  linhas de AcquirerWalletBalanceService::list()
     * @return array{
     *     caixa_disponivel: float,
     *     caixa_a_liberar: float,
     *     sellers_disponivel: float,
     *     sellers_a_liberar: float,
     *     sellers_a_liberar_por_metodo: array{pix: float, card: float, boleto: float},
     *     proxima_liberacao: string|null,
     *     saques_pendentes: float,
     *     saques_pendentes_qtd: int,
     *     saques_pendentes_mais_antigo: string|null,
     *     devido_agora: float,
     *     sobra_agora: float,
     *     sobra_total: float,
     *     adquirentes_com_erro: int,
     *     adquirentes_moeda_ignorada: int
     * }
     */
    public static function build(array $acquirerWallets): array
    {
        $caixa = 0.0;
        $aLiberar = 0.0;
        $erros = 0;
        $outraMoeda = 0;

        foreach ($acquirerWallets as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'error') {
                $erros++;

                continue;
            }
            if ($status !== 'ok') {
                continue;
            }
            if (strtoupper((string) ($row['currency'] ?? 'BRL')) !== 'BRL') {
                $outraMoeda++;

                continue;
            }
            $caixa += (float) ($row['available'] ?? 0);
            $aLiberar += (float) ($row['pending'] ?? 0);
        }

        $sellersDisponivel = self::walletSum('available_balance');
        $sellersALiberar = self::walletSum('pending_balance');
        $saques = self::pendingWithdrawals();

        $devidoAgora = $sellersDisponivel + $saques['amount'];
        $sobraAgora = $caixa - $devidoAgora;
        $sobraTotal = ($caixa + $aLiberar) - ($devidoAgora + $sellersALiberar);

        return [
            'caixa_disponivel' => round($caixa, 2),
            'caixa_a_liberar' => round($aLiberar, 2),
            'sellers_disponivel' => round($sellersDisponivel, 2),
            'sellers_a_liberar' => round($sellersALiberar, 2),
            'sellers_a_liberar_por_metodo' => [
                'pix' => self::walletSum('pending_pix'),
                'card' => self::walletSum('pending_card'),
                'boleto' => self::walletSum('pending_boleto'),
            ],
            'proxima_liberacao' => self::nextRelease(),
            'saques_pendentes' => round($saques['amount'], 2),
            'saques_pendentes_qtd' => $saques['count'],
            'saques_pendentes_mais_antigo' => $saques['oldest'],
            'devido_agora' => round($devidoAgora, 2),
            'sobra_agora' => round($sobraAgora, 2),
            'sobra_total' => round($sobraTotal, 2),
            'adquirentes_com_erro' => $erros,
            'adquirentes_moeda_ignorada' => $outraMoeda,
        ];
    }

    /**
     * Data (ISO) da próxima liberação de venda pendente dos sellers; null se não houver.
     */
    private static function nextRelease(): ?string
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return null;
        }

        $next = null;
        WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
            ->select(['id', 'meta'])
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$next) {
                foreach ($chunk as $tx) {
                    $meta = is_array($tx->meta) ? $tx->meta : [];
                    if (! empty($meta['released_at'])) {
                        continue;
                    }
                    $clears = $meta['clears_at'] ?? null;
                    if (! is_string($clears) || $clears === '') {
                        continue;
                    }
                    if ($next === null || strcmp($clears, $next) < 0) {
                        $next = $clears;
                    }
                }
            });

        return $next;
    }

    private static function walletSum(string $column): float
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasColumn('tenant_wallets', $column)) {
            return 0.0;
        }

        return round((float) TenantWallet::query()->sum($column), 2);
    }

    /**
     * @return array{amount: float, count: int, oldest: string|null}
     */
    private static function pendingWithdrawals(): array
    {
        if (! Schema::hasTable('withdrawals')) {
            return ['amount' => 0.0, 'count' => 0, 'oldest' => null];
        }

        $row = Withdrawal::query()
            ->whereIn('status', [MerchantWithdrawalService::STATUS_PENDING, MerchantWithdrawalService::STATUS_PROCESSING])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as quantidade, MIN(created_at) as mais_antigo')
            ->first();

        return [
            'amount' => round((float) ($row->total ?? 0), 2),
            'count' => (int) ($row->quantidade ?? 0),
            'oldest' => $row?->mais_antigo !== null ? (string) $row->mais_antigo : null,
        ];
    }
}
