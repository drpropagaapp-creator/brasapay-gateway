<?php

namespace App\Jobs;

use App\Gateways\Xflow\XflowDriver;
use App\Models\GatewayCredential;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Consulta GET /api/v1/transfers/{id} na Xflow até o saque constar como pago.
 */
class ReconcileXflowWithdrawalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 90;

    public function __construct(public int $withdrawalId) {}

    public function handle(): void
    {
        $withdrawal = Withdrawal::query()->find($this->withdrawalId);
        if ($withdrawal === null
            || ! in_array($withdrawal->status, ['pending', 'processing'], true)
            || $withdrawal->payout_provider !== 'xflow') {
            return;
        }

        $tx = trim((string) $withdrawal->payout_external_id);
        if ($tx === '') {
            return;
        }

        $cred = GatewayCredential::resolveForPayment(null, 'xflow');
        if ($cred === null || ! $cred->is_connected) {
            $this->maybeReleaseForRetry();

            return;
        }

        $credentials = $cred->getDecryptedCredentials();
        if ($credentials === []) {
            return;
        }

        $driver = new XflowDriver;
        try {
            $apiStatus = $driver->getTransferStatus($tx, $credentials);
        } catch (\Throwable $e) {
            Log::warning('ReconcileXflowWithdrawalJob: falha na consulta', [
                'withdrawal_id' => $this->withdrawalId,
                'message' => $e->getMessage(),
            ]);
            $this->maybeReleaseForRetry();

            return;
        }

        if ($apiStatus === 'paid') {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());

            return;
        }

        if (in_array($apiStatus, ['cancelled', 'failed'], true)) {
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout Xflow falhou (reconciliação): '.(string) $apiStatus
            );

            return;
        }

        $this->maybeReleaseForRetry();
    }

    private function maybeReleaseForRetry(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        if ($this->attempts() >= 36) {
            $withdrawal = Withdrawal::query()->find($this->withdrawalId);
            if ($withdrawal !== null && in_array($withdrawal->status, ['pending', 'processing'], true)) {
                MerchantWithdrawalService::markFailed(
                    $withdrawal->fresh(),
                    'Payout Xflow não confirmado após esgotar tentativas de reconciliação.'
                );
            }

            return;
        }

        $this->release(120);
    }
}
