<?php

namespace App\Services\Platform;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Support\PlatformFiscalPeriod;
use App\Support\SqlDialect;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Relatório Fiscal: receita de taxas da plataforma por competência do ledger.
 *
 * Fonte única para cards, tabela, exportação e futuro detalhe por infoprodutor
 * (`originalSaleFeeLinesQuery` / `retainedWithdrawalFeeQuery`).
 */
class PlatformFiscalReportService
{
    /**
     * Linhas ORIGINAIS de retenção da taxa de venda.
     *
     * @return Builder<WalletTransaction>
     */
    public function originalSaleFeeLinesQuery(string $start, string $end, ?int $tenantId = null): Builder
    {
        $query = WalletTransaction::query()
            ->where(function (Builder $q) {
                $q->where('type', WalletTransaction::TYPE_CREDIT_SALE_PENDING)
                    ->orWhere(function (Builder $inner) {
                        $inner->where('type', WalletTransaction::TYPE_CREDIT_SALE)
                            ->whereRaw(SqlDialect::jsonKeyMissingOrEmpty('meta', 'from_pending_wallet_transaction_id'));
                    });
            })
            ->whereBetween('created_at', [$start, $end]);

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }

    /**
     * Saques cuja taxa permanece retida pela plataforma.
     *
     * @return Builder<Withdrawal>
     */
    public function retainedWithdrawalFeeQuery(string $start, string $end, ?int $tenantId = null): Builder
    {
        $query = Withdrawal::query()
            ->whereIn('status', [
                MerchantWithdrawalService::STATUS_PENDING,
                MerchantWithdrawalService::STATUS_PROCESSING,
                MerchantWithdrawalService::STATUS_PAID,
            ])
            ->whereBetween('created_at', [$start, $end]);

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }

    /**
     * @return array{
     *     sale_fees: float,
     *     withdrawal_fees: float,
     *     total_fees: float,
     *     sales_count: int,
     *     volume: float,
     *     withdrawals_count: int
     * }
     */
    public function cards(string $start, string $end): array
    {
        $empty = $this->emptyTotals();
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('withdrawals')) {
            return $empty;
        }

        $sales = $this->originalSaleFeeLinesQuery($start, $end)
            ->selectRaw('COALESCE(SUM(amount_fee), 0) as fees, COALESCE(SUM(amount_gross), 0) as volume, COUNT(DISTINCT order_id) as sales_count')
            ->first();

        $withdrawals = $this->retainedWithdrawalFeeQuery($start, $end)
            ->selectRaw('COALESCE(SUM(fee_amount), 0) as fees, COUNT(*) as withdrawals_count')
            ->first();

        $saleFees = round((float) ($sales->fees ?? 0), 2);
        $withdrawalFees = round((float) ($withdrawals->fees ?? 0), 2);

        return [
            'sale_fees' => $saleFees,
            'withdrawal_fees' => $withdrawalFees,
            'total_fees' => round($saleFees + $withdrawalFees, 2),
            'sales_count' => (int) ($sales->sales_count ?? 0),
            'volume' => round((float) ($sales->volume ?? 0), 2),
            'withdrawals_count' => (int) ($withdrawals->withdrawals_count ?? 0),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function sellerRows(string $start, string $end, string $search = '', string $sortBy = 'total_fees', string $sortDirection = 'desc'): Collection
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('withdrawals')) {
            return collect();
        }

        $sales = $this->originalSaleFeeLinesQuery($start, $end)
            ->selectRaw('tenant_id, COUNT(DISTINCT order_id) as sales_count, COALESCE(SUM(amount_gross), 0) as volume, COALESCE(SUM(amount_fee), 0) as sale_fees')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->tenant_id);

        $withdrawals = $this->retainedWithdrawalFeeQuery($start, $end)
            ->selectRaw('tenant_id, COUNT(*) as withdrawals_count, COALESCE(SUM(fee_amount), 0) as withdrawal_fees')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->tenant_id);

        $tenantIds = $sales->keys()->merge($withdrawals->keys())->unique()->filter(fn ($id) => (int) $id > 0)->values();
        if ($tenantIds->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $tenantIds->all())
            ->get(['id', 'name', 'email', 'document', 'company_name', 'trade_name', 'person_type'])
            ->keyBy('id');

        $needle = mb_strtolower(trim($search));
        $needleDigits = preg_replace('/\D+/', '', $needle) ?? '';

        $rows = $tenantIds->map(function ($tenantId) use ($sales, $withdrawals, $users) {
            $tenantId = (int) $tenantId;
            $user = $users->get($tenantId);
            $sale = $sales->get($tenantId);
            $wd = $withdrawals->get($tenantId);
            $saleFees = round((float) ($sale->sale_fees ?? 0), 2);
            $withdrawalFees = round((float) ($wd->withdrawal_fees ?? 0), 2);
            $company = trim((string) ($user?->company_name ?? ''));
            $name = $company !== '' ? $company : trim((string) ($user?->name ?? 'Infoprodutor #'.$tenantId));

            return [
                'tenant_id' => $tenantId,
                'name' => $name,
                'legal_name' => $company !== '' ? $company : trim((string) ($user?->name ?? '')),
                'trade_name' => trim((string) ($user?->trade_name ?? '')),
                'email' => (string) ($user?->email ?? ''),
                'document' => (string) ($user?->document ?? ''),
                'sales_count' => (int) ($sale->sales_count ?? 0),
                'volume' => round((float) ($sale->volume ?? 0), 2),
                'sale_fees' => $saleFees,
                'withdrawals_count' => (int) ($wd->withdrawals_count ?? 0),
                'withdrawal_fees' => $withdrawalFees,
                'total_fees' => round($saleFees + $withdrawalFees, 2),
            ];
        });

        if ($needle !== '') {
            $rows = $rows->filter(function (array $row) use ($needle, $needleDigits) {
                $haystack = mb_strtolower(implode(' ', [
                    $row['name'],
                    $row['legal_name'],
                    $row['trade_name'],
                    $row['email'],
                    $row['document'],
                ]));
                if (str_contains($haystack, $needle)) {
                    return true;
                }
                if ($needleDigits !== '' && str_contains(preg_replace('/\D+/', '', $row['document']) ?? '', $needleDigits)) {
                    return true;
                }

                return false;
            })->values();
        }

        $desc = $sortDirection !== 'asc';

        return $rows->sort(function (array $a, array $b) use ($sortBy, $desc) {
            $left = $a[$sortBy] ?? null;
            $right = $b[$sortBy] ?? null;
            if (is_string($left) || is_string($right)) {
                $cmp = strcmp(mb_strtolower((string) $left), mb_strtolower((string) $right));
            } else {
                $cmp = $left <=> $right;
            }
            if ($cmp === 0) {
                $cmp = ($b['total_fees'] <=> $a['total_fees']);
                if ($cmp === 0) {
                    $cmp = ($a['tenant_id'] <=> $b['tenant_id']);
                }
            }

            return $desc ? -$cmp : $cmp;
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function page(
        PlatformFiscalPeriod $period,
        string $search = '',
        string $sortBy = 'total_fees',
        string $sortDirection = 'desc',
        int $perPage = 25,
        int $page = 1,
    ): array {
        $cards = $this->cards($period->start, $period->end);
        $allRows = $this->sellerRows($period->start, $period->end, $search, $sortBy, $sortDirection);
        $page = max(1, $page);
        $paginator = new LengthAwarePaginator(
            $allRows->forPage($page, $perPage)->values()->all(),
            $allRows->count(),
            $perPage,
            $page,
            ['path' => '/plataforma/fiscal', 'query' => array_filter(array_merge($period->queryParams(), [
                'q' => $search !== '' ? $search : null,
                'sort_by' => $sortBy,
                'sort_direction' => $sortDirection,
                'per_page' => $perPage,
            ]), fn ($v) => $v !== null && $v !== '')]
        );

        return [
            'period' => $period->period,
            'period_label' => $period->label(),
            'start' => $period->start,
            'end' => $period->end,
            'year' => $period->year,
            'month' => $period->month,
            'from' => $period->fromDate,
            'to' => $period->toDate,
            'q' => $search,
            'sort_by' => $sortBy,
            'sort_direction' => $sortDirection,
            'per_page' => $perPage,
            'cards' => $cards,
            'totals' => $cards,
            'sellers' => $paginator,
            'year_options' => $this->yearOptions($period->year),
            'month_options' => collect(PlatformFiscalPeriod::MONTH_LABELS)
                ->map(fn (string $label, int $num) => ['value' => $num, 'label' => $label])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{sale_fees: float, withdrawal_fees: float, total_fees: float, sales_count: int, volume: float, withdrawals_count: int}
     */
    private function emptyTotals(): array
    {
        return [
            'sale_fees' => 0.0,
            'withdrawal_fees' => 0.0,
            'total_fees' => 0.0,
            'sales_count' => 0,
            'volume' => 0.0,
            'withdrawals_count' => 0,
        ];
    }

    /**
     * @return list<int>
     */
    private function yearOptions(int $current): array
    {
        $max = max($current, (int) Carbon::now()->year);
        $years = [];
        for ($y = $max; $y >= 2020; $y--) {
            $years[] = $y;
        }

        return $years;
    }
}
