<?php

namespace App\Services\Treasury;

use App\Models\Order;
use App\Models\OrderSplitSnapshot;
use App\Models\TreasuryDiscrepancy;
use App\Models\TreasuryRun;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Support\SqlDialect;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Treasury — conciliação interna em modo OBSERVAÇÃO.
 *
 * Confronta pedidos, lançamentos da carteira, snapshots de split, MED e saques, e registra
 * divergências em treasury_discrepancies. Nunca altera saldo nem lançamento: corrigir é uma
 * decisão do admin. Idempotente: a mesma ocorrência tem sempre o mesmo fingerprint, e o que
 * deixou de ocorrer é encerrado automaticamente na execução seguinte.
 */
class TreasuryReconciliationService
{
    /** Tipos de divergência, com severidade e texto para o painel. */
    public const KINDS = [
        'order_paid_without_credit' => ['critical', 'Pedido pago sem crédito na carteira'],
        'credit_above_order' => ['critical', 'Crédito acima do valor do pedido'],
        'credit_above_split' => ['critical', 'Crédito acima da participação registrada no split'],
        'refund_without_wallet_debit' => ['critical', 'Reembolso sem estorno na carteira'],
        'med_hold_open_outside_dispute' => ['high', 'Retenção de MED aberta em pedido fora de disputa'],
        'settlement_release_overdue' => ['attention', 'Liberação de saldo atrasada'],
        'withdrawal_paid_without_debit' => ['critical', 'Saque pago sem débito na carteira'],
        'withdrawal_failed_without_refund' => ['high', 'Saque falho sem devolução do saldo'],
        'withdrawal_reconcile_exhausted' => ['high', 'Saque sem confirmação da adquirente (em investigação)'],
        'withdrawal_stuck_at_provider' => ['attention', 'Saque enviado à adquirente sem confirmação há mais de 6 h'],
        'withdrawal_awaiting_approval' => ['info', 'Saque aguardando aprovação há mais de 48 h'],
    ];

    private const LOCK_KEY = 'treasury:reconcile';

    public function __construct(private int $windowDays = 90) {}

    /**
     * @return array{run_id:int|null, counts: array<string,int>, skipped?: bool}
     */
    public function run(?int $triggeredBy = null): array
    {
        if (! Schema::hasTable('treasury_discrepancies') || ! Schema::hasTable('wallet_transactions')) {
            return ['run_id' => null, 'counts' => [], 'skipped' => true];
        }

        $lock = Cache::lock(self::LOCK_KEY, 600);
        if (! $lock->get()) {
            return ['run_id' => null, 'counts' => [], 'skipped' => true];
        }

        $run = TreasuryRun::query()->create(['started_at' => now(), 'status' => 'running', 'triggered_by' => $triggeredBy]);
        try {
            $since = now()->subDays($this->windowDays);
            $findings = array_merge(
                $this->ordersPaidWithoutCredit($since),
                $this->creditsAboveOrder($since),
                $this->creditsAboveSplit($since),
                $this->refundsWithoutWalletDebit($since),
                $this->medHoldsOutsideDispute(),
                $this->settlementReleasesOverdue($since),
                $this->withdrawalChecks($since),
            );
            $counts = $this->persist($findings);
            $run->update(['finished_at' => now(), 'status' => 'done', 'counts' => $counts]);

            return ['run_id' => $run->id, 'counts' => $counts];
        } catch (\Throwable $e) {
            Log::error('Treasury: conciliação falhou', ['message' => $e->getMessage()]);
            $run->update(['finished_at' => now(), 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    // ── Pedidos ────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function ordersPaidWithoutCredit(CarbonInterface $since): array
    {
        $out = [];
        Order::query()
            ->where('status', 'completed')
            ->where('amount', '>', 0)
            ->whereBetween('created_at', [$since, now()->subMinutes(30)])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('wallet_transactions')
                    ->whereColumn('wallet_transactions.order_id', 'orders.id')
                    ->whereIn('wallet_transactions.type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING]);
            })
            ->select(['id', 'tenant_id', 'amount', 'gateway', 'payment_method', 'created_at'])
            ->limit(500)
            ->get()
            ->each(function (Order $o) use (&$out) {
                $out[] = $this->finding('order_paid_without_credit', 'order:'.$o->id, [
                    'tenant_id' => $o->tenant_id, 'order_id' => (string) $o->id,
                    'amount_cents' => self::cents($o->amount),
                    'details' => ['gateway' => $o->gateway, 'payment_method' => $o->payment_method, 'paid_at' => $o->created_at?->toIso8601String()],
                ]);
            });

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function creditsAboveOrder(CarbonInterface $since): array
    {
        $rows = WalletTransaction::query()
            ->originalSaleFeeLines()
            ->where('created_at', '>=', $since)
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(amount_gross) as gross')
            ->get();

        $out = [];
        $orders = Order::query()->whereIn('id', $rows->pluck('order_id')->all())->get()->keyBy(fn ($o) => (string) $o->id);
        foreach ($rows as $row) {
            $order = $orders->get((string) $row->order_id);
            if ($order === null) {
                continue;
            }
            $credited = self::cents($row->gross);
            $expected = max(self::cents($order->amount), self::cents($order->lineItemsTotalAmount()));
            if ($credited > $expected + 1) {
                $out[] = $this->finding('credit_above_order', 'order:'.$order->id, [
                    'tenant_id' => $order->tenant_id, 'order_id' => (string) $order->id,
                    'amount_cents' => $credited - $expected,
                    'details' => ['credited_gross_cents' => $credited, 'order_total_cents' => $expected],
                ]);
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function creditsAboveSplit(CarbonInterface $since): array
    {
        if (! Schema::hasTable('order_split_snapshots')) {
            return [];
        }

        $out = [];
        OrderSplitSnapshot::query()->where('created_at', '>=', $since)->chunkById(200, function ($snapshots) use (&$out) {
            $credited = WalletTransaction::query()
                ->originalSaleFeeLines()
                ->whereIn('order_id', $snapshots->pluck('order_id')->all())
                ->groupBy('order_id', 'tenant_id')
                ->selectRaw('order_id, tenant_id, SUM(amount_gross) as gross')
                ->get()
                ->groupBy(fn ($r) => (string) $r->order_id);

            foreach ($snapshots as $snap) {
                $slices = collect(is_array($snap->slices) ? $snap->slices : [])->keyBy(fn ($s) => (int) ($s['tenant_id'] ?? 0));
                foreach ($credited->get((string) $snap->order_id, collect()) as $row) {
                    $got = self::cents($row->gross);
                    $allowed = (int) ($slices->get((int) $row->tenant_id)['gross_cents'] ?? 0);
                    if ($got > $allowed + 1) {
                        $out[] = $this->finding('credit_above_split', 'order:'.$snap->order_id.':tenant:'.$row->tenant_id, [
                            'tenant_id' => (int) $row->tenant_id, 'order_id' => (string) $snap->order_id,
                            'amount_cents' => $got - $allowed,
                            'details' => ['credited_gross_cents' => $got, 'split_gross_cents' => $allowed, 'rule_version' => $snap->rule_version],
                        ]);
                    }
                }
            }
        });

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function refundsWithoutWalletDebit(CarbonInterface $since): array
    {
        $out = [];
        Order::query()
            ->where('status', 'refunded')
            ->where('updated_at', '>=', $since)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('wallet_transactions')
                    ->whereColumn('wallet_transactions.order_id', 'orders.id')
                    ->whereIn('wallet_transactions.type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING]);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('wallet_transactions')
                    ->whereColumn('wallet_transactions.order_id', 'orders.id')
                    ->where('wallet_transactions.type', WalletTransaction::TYPE_DEBIT_REFUND);
            })
            ->select(['id', 'tenant_id', 'amount'])
            ->limit(500)
            ->get()
            ->each(function (Order $o) use (&$out) {
                $out[] = $this->finding('refund_without_wallet_debit', 'order:'.$o->id, [
                    'tenant_id' => $o->tenant_id, 'order_id' => (string) $o->id, 'amount_cents' => self::cents($o->amount),
                ]);
            });

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function medHoldsOutsideDispute(): array
    {
        $out = [];
        WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_MED_HOLD)
            ->whereRaw(SqlDialect::jsonKeyMissingOrEmpty('meta', 'released_at'))
            ->whereNotNull('order_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('orders')
                    ->whereColumn('orders.id', 'wallet_transactions.order_id')
                    ->where('orders.status', 'disputed');
            })
            ->select(['id', 'tenant_id', 'order_id', 'amount_net', 'meta'])
            ->limit(500)
            ->get()
            ->each(function (WalletTransaction $h) use (&$out) {
                $meta = is_array($h->meta) ? $h->meta : [];
                $out[] = $this->finding('med_hold_open_outside_dispute', 'med_hold:'.$h->id, [
                    'tenant_id' => $h->tenant_id, 'order_id' => (string) $h->order_id,
                    'amount_cents' => self::cents($meta['held_net'] ?? $h->amount_net),
                ]);
            });

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function settlementReleasesOverdue(CarbonInterface $since): array
    {
        $out = [];
        $limit = now()->subDay();
        WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
            ->where('created_at', '>=', $since)
            ->whereRaw(SqlDialect::jsonKeyMissingOrEmpty('meta', 'released_at'))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('orders')
                    ->whereColumn('orders.id', 'wallet_transactions.order_id')
                    ->where('orders.status', 'completed');
            })
            ->select(['id', 'tenant_id', 'order_id', 'amount_net', 'meta'])
            ->chunkById(500, function ($rows) use (&$out, $limit) {
                foreach ($rows as $tx) {
                    $clearsAt = (is_array($tx->meta) ? $tx->meta : [])['clears_at'] ?? null;
                    try {
                        $when = $clearsAt ? \Illuminate\Support\Carbon::parse($clearsAt) : null;
                    } catch (\Throwable) {
                        $when = null;
                    }
                    if ($when === null || $when->greaterThan($limit)) {
                        continue;
                    }
                    $out[] = $this->finding('settlement_release_overdue', 'credit_pending:'.$tx->id, [
                        'tenant_id' => $tx->tenant_id, 'order_id' => (string) $tx->order_id,
                        'amount_cents' => self::cents($tx->amount_net),
                        'details' => ['clears_at' => $when->toIso8601String()],
                    ]);
                }
            });

        return $out;
    }

    // ── Saques ─────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function withdrawalChecks(CarbonInterface $since): array
    {
        if (! Schema::hasTable('withdrawals')) {
            return [];
        }

        $out = [];
        $ledger = fn (string $type) => function ($q) use ($type) {
            $q->select(DB::raw(1))->from('wallet_transactions')
                ->whereColumn('wallet_transactions.withdrawal_id', 'withdrawals.id')
                ->where('wallet_transactions.type', $type);
        };
        $base = fn () => Withdrawal::query()->where('created_at', '>=', $since)
            ->select(['id', 'tenant_id', 'amount', 'net_amount', 'status', 'payout_provider', 'payout_external_id', 'payout_meta', 'created_at']);
        $add = function (string $kind, Withdrawal $w, array $details = []) use (&$out) {
            $out[] = $this->finding($kind, 'withdrawal:'.$w->id, [
                'tenant_id' => $w->tenant_id, 'withdrawal_id' => $w->id, 'amount_cents' => self::cents($w->amount),
                'details' => array_merge(['provider' => $w->payout_provider, 'status' => $w->status], $details),
            ]);
        };

        $base()->where('status', 'paid')->whereNotExists($ledger(WalletTransaction::TYPE_WITHDRAWAL_REQUEST))
            ->limit(500)->get()->each(fn ($w) => $add('withdrawal_paid_without_debit', $w));

        $base()->whereIn('status', ['failed', 'rejected'])
            ->whereExists($ledger(WalletTransaction::TYPE_WITHDRAWAL_REQUEST))
            ->whereNotExists($ledger(WalletTransaction::TYPE_WITHDRAWAL_REFUND))
            ->limit(500)->get()->each(fn ($w) => $add('withdrawal_failed_without_refund', $w));

        $base()->whereIn('status', ['pending', 'processing'])->limit(2000)->get()->each(function (Withdrawal $w) use ($add) {
            $meta = is_array($w->payout_meta) ? $w->payout_meta : [];
            $sentToProvider = $w->status === 'processing' || trim((string) $w->payout_external_id) !== '';
            if (! empty($meta['reconcile_exhausted'])) {
                $add('withdrawal_reconcile_exhausted', $w, ['exhausted_at' => $meta['reconcile_exhausted_at'] ?? null]);
            } elseif ($sentToProvider && $w->created_at?->lt(now()->subHours(6))) {
                $add('withdrawal_stuck_at_provider', $w, ['requested_at' => $w->created_at?->toIso8601String()]);
            } elseif (! $sentToProvider && $w->created_at?->lt(now()->subHours(48))) {
                $add('withdrawal_awaiting_approval', $w, ['requested_at' => $w->created_at?->toIso8601String()]);
            }
        });

        return $out;
    }

    // ── Persistência ───────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function finding(string $kind, string $ref, array $attrs): array
    {
        [$severity] = self::KINDS[$kind];

        return array_merge(['kind' => $kind, 'severity' => $severity, 'fingerprint' => $kind.'|'.$ref, 'details' => null], $attrs);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    private function persist(array $findings): array
    {
        $now = now();
        $seen = [];
        $counts = array_fill_keys(array_keys(self::KINDS), 0) + ['new' => 0, 'auto_resolved' => 0];

        foreach ($findings as $f) {
            $seen[$f['fingerprint']] = true;
            $counts[$f['kind']]++;
            $row = TreasuryDiscrepancy::query()->where('fingerprint', $f['fingerprint'])->first();
            $payload = [
                'severity' => $f['severity'],
                'tenant_id' => $f['tenant_id'] ?? null,
                'order_id' => $f['order_id'] ?? null,
                'withdrawal_id' => $f['withdrawal_id'] ?? null,
                'amount_cents' => (int) ($f['amount_cents'] ?? 0),
                'details' => $f['details'],
                'last_seen_at' => $now,
            ];
            if ($row === null) {
                TreasuryDiscrepancy::query()->create($payload + [
                    'kind' => $f['kind'],
                    'fingerprint' => $f['fingerprint'],
                    'status' => $f['kind'] === 'withdrawal_reconcile_exhausted'
                        ? TreasuryDiscrepancy::STATUS_INVESTIGATING
                        : TreasuryDiscrepancy::STATUS_OPEN,
                    'first_seen_at' => $now,
                ]);
                $counts['new']++;

                continue;
            }
            // Ignorada pelo admin continua ignorada; resolvida que voltou a ocorrer é reaberta.
            if ($row->status === TreasuryDiscrepancy::STATUS_RESOLVED) {
                $payload += ['status' => TreasuryDiscrepancy::STATUS_OPEN, 'resolved_at' => null, 'resolved_by' => null,
                    'resolution_note' => trim(($row->resolution_note ? $row->resolution_note."\n" : '').'Voltou a ocorrer em '.$now->format('d/m/Y H:i').'.')];
            }
            $row->update($payload);
        }

        // O que estava ativo e não apareceu nesta execução deixou de ocorrer.
        TreasuryDiscrepancy::query()
            ->whereIn('status', [TreasuryDiscrepancy::STATUS_OPEN, TreasuryDiscrepancy::STATUS_INVESTIGATING])
            ->whereIn('kind', array_keys(self::KINDS))
            ->select(['id', 'fingerprint'])
            ->chunkById(500, function ($rows) use ($seen, $now, &$counts) {
                foreach ($rows as $row) {
                    if (isset($seen[$row->fingerprint])) {
                        continue;
                    }
                    TreasuryDiscrepancy::query()->whereKey($row->id)->update([
                        'status' => TreasuryDiscrepancy::STATUS_RESOLVED,
                        'resolved_at' => $now,
                        'resolved_by' => null,
                        'resolution_note' => 'Encerrada automaticamente: deixou de ocorrer na conciliação de '.$now->format('d/m/Y H:i').'.',
                        'updated_at' => $now,
                    ]);
                    $counts['auto_resolved']++;
                }
            });

        return $counts;
    }

    private static function cents(mixed $value): int
    {
        return (int) bcmul(sprintf('%.2F', (float) $value), '100', 0);
    }
}
