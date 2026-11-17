<?php

namespace App\Support;

use App\Models\Order;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PendingReceiveSchedule
{
    /**
     * Valores em liquidação agrupados pela data prevista de liberação (clears_at).
     * Pedido estornado ou com estorno em andamento já saiu do saldo e não entra na lista.
     *
     * @return list<array{date: ?string, amount: float, count: int, reasons: list<array{portion: string, method: string, amount: float}>}>
     */
    public static function forTenant(int $tenantId): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return [];
        }

        $transactions = WalletTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
            ->orderBy('id')
            ->get();

        $refundedOrderIds = self::refundedOrderIds($transactions);
        $byDate = [];

        foreach ($transactions as $tx) {
            $meta = is_array($tx->meta) ? $tx->meta : [];
            if (! empty($meta['released_at'])) {
                continue;
            }
            if (isset($refundedOrderIds[(int) $tx->order_id])) {
                continue;
            }

            $dateKey = 'unknown';
            $clearsAt = $meta['clears_at'] ?? null;
            if (is_string($clearsAt) && $clearsAt !== '') {
                try {
                    $dateKey = Carbon::parse($clearsAt)->format('Y-m-d');
                } catch (\Throwable) {
                    $dateKey = 'unknown';
                }
            }

            if (! isset($byDate[$dateKey])) {
                $byDate[$dateKey] = [
                    'date' => $dateKey === 'unknown' ? null : $dateKey,
                    'amount' => 0.0,
                    'count' => 0,
                    'reasons' => [],
                ];
            }

            $byDate[$dateKey]['amount'] += (float) $tx->amount_net;
            $byDate[$dateKey]['count']++;

            // BrasaPay: motivo da retenção — venda dentro do prazo da operadora ou reserva de segurança.
            $portion = ($meta['portion'] ?? 'main') === 'reserve' ? 'reserve' : 'main';
            $method = is_string($meta['payment_method'] ?? null) && $meta['payment_method'] !== ''
                ? $meta['payment_method']
                : (string) $tx->bucket;
            $reasonKey = $portion.'|'.$method;
            if (! isset($byDate[$dateKey]['reasons'][$reasonKey])) {
                $byDate[$dateKey]['reasons'][$reasonKey] = [
                    'portion' => $portion,
                    'method' => $method,
                    'amount' => 0.0,
                ];
            }
            $byDate[$dateKey]['reasons'][$reasonKey]['amount'] += (float) $tx->amount_net;
        }

        $rows = array_values($byDate);
        usort($rows, function (array $a, array $b) {
            if ($a['date'] === null) {
                return 1;
            }
            if ($b['date'] === null) {
                return -1;
            }

            return strcmp($a['date'], $b['date']);
        });

        return array_map(function (array $row) {
            $row['amount'] = round($row['amount'], 2);
            $row['reasons'] = array_values(array_map(function (array $r) {
                $r['amount'] = round($r['amount'], 2);

                return $r;
            }, $row['reasons'] ?? []));

            return $row;
        }, $rows);
    }

    /**
     * @param  Collection<int, WalletTransaction>  $transactions
     * @return array<int, true>
     */
    private static function refundedOrderIds(Collection $transactions): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        $orderIds = $transactions->pluck('order_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        return Order::query()
            ->whereIn('id', $orderIds->all())
            ->whereIn('status', ['refunded', 'refund_pending'])
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
