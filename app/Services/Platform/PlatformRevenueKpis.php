<?php

namespace App\Services\Platform;

use App\Models\Order;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\MerchantWithdrawalService;
use App\Services\Payout\PlatformPayoutGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PlatformRevenueKpis
{
    /**
     * @return array{
     *     faturamento_taxas_cobradas: float,
     *     faturamento_custo_adquirente_vendas: float,
     *     faturamento_custo_adquirente_saques: float,
     *     faturamento_liquido: float
     * }
     */
    public static function compute(?string $start, ?string $end): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return self::zeros();
        }

        $orderIds = self::ordersInPeriodQuery($start, $end)->pluck('id');
        $taxasCobradas = self::sumMerchantFeesForOrderIds($orderIds);

        $custoVendas = 0.0;
        self::ordersInPeriodQuery($start, $end)
            ->select(['id', 'payment_method', 'metadata', 'gateway', 'amount'])
            ->chunkById(500, function ($orders) use (&$custoVendas) {
                foreach ($orders as $order) {
                    $slug = trim((string) ($order->gateway ?? ''));
                    if ($slug === '' || ! self::countsAcquirerSaleFee($order, $slug)) {
                        continue;
                    }
                    $e = GatewayPayoutEconomics::fromSlug($slug);
                    $custoVendas += GatewayPayoutEconomics::pixInFeeCostBrl(
                        (float) ($order->amount ?? 0),
                        (float) ($e['admin_fee_pix_brl'] ?? 0),
                        (float) ($e['admin_fee_pix_percent'] ?? 0),
                    );
                }
            });

        $custoSaques = 0.0;
        if (Schema::hasTable('withdrawals')) {
            // Saque concluído é "paid" (não existe "completed" em withdrawals): antes o custo saía sempre 0.
            $wdQ = Withdrawal::query()->where('status', MerchantWithdrawalService::STATUS_PAID);
            if ($start && $end) {
                $wdQ->whereBetween('created_at', [$start, $end]);
            } elseif ($start) {
                $wdQ->where('created_at', '>=', $start);
            } elseif ($end) {
                $wdQ->where('created_at', '<=', $end);
            }
            $wdQ->select(['id', 'payout_provider', 'net_amount'])->chunkById(500, function ($rows) use (&$custoSaques) {
                foreach ($rows as $w) {
                    $slug = $w->payout_provider;
                    if ($slug === null || $slug === '') {
                        $slug = PlatformPayoutGateway::activeSlug();
                    }
                    if ($slug === null || $slug === '') {
                        continue;
                    }
                    $e = GatewayPayoutEconomics::fromSlug($slug);
                    $custoSaques += GatewayPayoutEconomics::payoutFeeCostBrl(
                        (float) ($w->net_amount ?? 0),
                        (float) ($e['admin_fee_payout_brl'] ?? 0),
                        (float) ($e['admin_fee_payout_percent'] ?? 0),
                    );
                }
            });
        }

        $taxasCobradas = round($taxasCobradas, 2);
        $custoVendas = round($custoVendas, 2);
        $custoSaques = round($custoSaques, 2);
        $liquido = round($taxasCobradas - $custoVendas - $custoSaques, 2);

        return [
            'faturamento_taxas_cobradas' => $taxasCobradas,
            'faturamento_custo_adquirente_vendas' => $custoVendas,
            'faturamento_custo_adquirente_saques' => $custoSaques,
            'faturamento_liquido' => $liquido,
        ];
    }

    /**
     * @return array{faturamento_taxas_cobradas: float, faturamento_custo_adquirente_vendas: float, faturamento_custo_adquirente_saques: float, faturamento_liquido: float}
     */
    private static function zeros(): array
    {
        return [
            'faturamento_taxas_cobradas' => 0.0,
            'faturamento_custo_adquirente_vendas' => 0.0,
            'faturamento_custo_adquirente_saques' => 0.0,
            'faturamento_liquido' => 0.0,
        ];
    }

    private static function ordersInPeriodQuery(?string $start, ?string $end): Builder
    {
        $q = Order::query()->where('status', 'completed');
        if ($start && $end) {
            $q->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $q->where('created_at', '>=', $start);
        } elseif ($end) {
            $q->where('created_at', '<=', $end);
        }

        return $q;
    }

    /**
     * PIX: taxa de entrada do adquirente.
     * Cartão: só Stripe por enquanto — demais adquirentes têm taxa por parcelamento.
     */
    private static function countsAcquirerSaleFee(Order $order, string $slug): bool
    {
        $bucket = self::paymentBucket($order);

        if ($bucket === 'pix') {
            return true;
        }

        return $bucket === 'card' && $slug === 'stripe';
    }

    /**
     * Alinha ao listener de crédito: cartão/boleto vs PIX (default).
     */
    private static function paymentBucket(Order $order): string
    {
        $method = $order->payment_method;
        if ($method === null || $method === '') {
            $meta = $order->metadata ?? [];
            $method = is_array($meta) ? ($meta['checkout_payment_method'] ?? null) : null;
        }

        return match ($method) {
            'card' => 'card',
            'boleto' => 'boleto',
            'pix_auto', 'pix', null, '' => 'pix',
            default => 'pix',
        };
    }

    /**
     * Soma a taxa de cada parte da venda uma única vez (em centavos):
     * - credit_sale_pending: toda parte que nasceu pendente (principal, reserva, parcela), liberada ou não;
     * - credit_sale direto (D+0 sem reserva).
     * O credit_sale criado na liberação (meta.from_pending_wallet_transaction_id) repete a taxa da
     * parte pendente e fica de fora. Antes, havendo qualquer credit_sale, as partes ainda pendentes
     * eram ignoradas e a receita de taxas saía menor.
     *
     * @param  Collection<int, int>  $orderIds
     */
    private static function sumMerchantFeesForOrderIds(Collection $orderIds): float
    {
        if ($orderIds->isEmpty()) {
            return 0.0;
        }

        $total = '0';
        foreach ($orderIds->chunk(1000) as $chunk) {
            // SUM de decimal no banco; acumula como string decimal (bcmath) para não perder centavos.
            $sum = WalletTransaction::query()
                ->whereIn('order_id', $chunk->all())
                ->originalSaleFeeLines()
                ->sum('amount_fee');
            $total = bcadd($total, sprintf('%.2F', (float) $sum), 2);
        }

        return (float) $total;
    }
}
