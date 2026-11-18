<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Platform\AcquirerWalletBalanceService;
use App\Services\Platform\PayoutFundingNeed;
use App\Services\Payout\PlatformPayoutGateway;
use App\Services\Withdrawal\WithdrawalPolicyService;
use App\Services\WithdrawalPixReceiptService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class WithdrawalsController extends Controller
{
    private const WITHDRAWAL_STATUS_OPTIONS = ['all', 'pending', 'paid', 'rejected', 'failed'];

    public function __construct(
        protected WithdrawalPixReceiptService $receiptService,
    ) {}

    public function index(Request $request): Response
    {
        $withdrawalStatus = $request->query('withdrawal_status', 'all');
        if (! in_array($withdrawalStatus, self::WITHDRAWAL_STATUS_OPTIONS, true)) {
            $withdrawalStatus = 'all';
        }

        $origin = $request->query('origin', 'all');
        if (! in_array($origin, ['all', 'api'], true)) {
            $origin = 'all';
        }

        // Cobertura de saldo na conta de saque para cada saque pendente (aviso de abastecimento).
        $acquirerWallets = app(AcquirerWalletBalanceService::class)->list();
        $payoutFunding = PayoutFundingNeed::compute($acquirerWallets);

        $withdrawalsPaginator = new LengthAwarePaginator([], 0, 40, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        if (Schema::hasTable('withdrawals')) {
            $wq = Withdrawal::query()
                ->with(['tenantOwner:id,name,email', 'apiApplication:id,name'])
                ->orderByDesc('created_at');
            if ($withdrawalStatus !== 'all') {
                if ($withdrawalStatus === 'pending') {
                    $wq->whereIn('status', ['pending', 'processing']);
                } else {
                    $wq->where('status', $withdrawalStatus);
                }
            }
            if ($origin === 'api') {
                $wq->whereNotNull('api_application_id');
            }
            $withdrawalsPaginator = $wq->paginate(40)->withQueryString()->through(
                fn (Withdrawal $w) => $this->receiptService->mapWithdrawalListItem($w)
                    + ['funding' => $payoutFunding['items'][(int) $w->id] ?? null]
            );
        }

        return Inertia::render('Platform/Withdrawals/Index', [
            'withdrawals' => $withdrawalsPaginator,
            'filters' => [
                'withdrawal_status' => $withdrawalStatus,
                'origin' => $origin,
            ],
            'payout_gateway_active' => PlatformPayoutGateway::activeSlug() ?? '',
            'payout_funding' => $payoutFunding,
            'cashout_acquirers' => self::withBalances(PlatformPayoutGateway::connectedOptions(), $acquirerWallets),
            'require_manual_approval_pin' => $request->user() !== null
                && WithdrawalPolicyService::requiresOperationPinFor($request->user()),
            'has_manual_approval_pin' => WithdrawalPolicyService::hasManualApprovalPin(),
            'manual_payout_acquirers' => $this->receiptService->manualPayoutSourceOptions(),
        ]);
    }

    /**
     * Junta o saldo disponível de cada adquirente às opções de "Quem envia o PIX?".
     * CajuPay com várias contas soma as contas que responderam.
     *
     * @param  list<array{slug: string, name: string}>  $options
     * @param  list<array<string, mixed>>  $wallets  linhas de AcquirerWalletBalanceService::list()
     * @return list<array{slug: string, name: string, available: float|null, balance_status: string}>
     */
    public static function withBalances(array $options, array $wallets): array
    {
        $bySlug = [];
        foreach ($wallets as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $entry = $bySlug[$slug] ?? ['sum' => 0.0, 'ok' => 0, 'error' => 0];
            if (($row['status'] ?? '') === 'ok' && is_numeric($row['available'] ?? null)) {
                $entry['sum'] += (float) $row['available'];
                $entry['ok']++;
            } elseif (($row['status'] ?? '') === 'error') {
                $entry['error']++;
            }
            $bySlug[$slug] = $entry;
        }

        return array_map(function (array $option) use ($bySlug): array {
            $entry = $bySlug[$option['slug']] ?? null;
            $status = match (true) {
                $entry === null => 'unsupported',
                $entry['ok'] > 0 && $entry['error'] === 0 => 'ok',
                $entry['ok'] > 0 => 'partial',
                $entry['error'] > 0 => 'error',
                default => 'unsupported',
            };

            return $option + [
                'available' => $entry !== null && $entry['ok'] > 0 ? round($entry['sum'], 2) : null,
                'balance_status' => $status,
            ];
        }, $options);
    }
}
