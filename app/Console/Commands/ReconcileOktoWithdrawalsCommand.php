<?php

namespace App\Console\Commands;

use App\Gateways\Okto\OktoDriver;
use App\Models\GatewayCredential;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileOktoWithdrawalsCommand extends Command
{
    protected $signature = 'withdrawals:reconcile-okto
                            {--limit=80 : Máximo de saques para checar por execução}
                            {--min-age-minutes=1 : Ignorar registros atualizados há menos de X minutos}
                            {--withdrawal= : ID interno do saque (um registro; ignora min-age)}';

    protected $description = 'Consulta na Okto saques PIX automáticos ainda pendentes e marca como pagos quando a API já liquidou.';

    public function handle(): int
    {
        if (! Schema::hasTable('withdrawals')) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $minAge = max(0, (int) $this->option('min-age-minutes'));
        $onlyId = $this->option('withdrawal');

        $cred = GatewayCredential::resolveForPayment(null, 'okto');
        if ($cred === null || ! $cred->is_connected) {
            $this->warn('Credencial Okto (plataforma) não conectada.');

            return self::SUCCESS;
        }

        $credentials = $cred->getDecryptedCredentials();
        if ($credentials === []) {
            return self::SUCCESS;
        }

        $driver = new OktoDriver;

        if ($onlyId !== null && $onlyId !== '') {
            $w = Withdrawal::query()->find((int) $onlyId);
            if ($w === null) {
                $this->error('Saque não encontrado.');

                return self::FAILURE;
            }
            $this->reconcileOne($w, $driver, $credentials);

            return self::SUCCESS;
        }

        $q = Withdrawal::query()
            ->where('payout_provider', 'okto')
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('id');

        if ($minAge > 0) {
            $q->where('updated_at', '<=', now()->subMinutes($minAge));
        }

        $rows = $q->limit($limit)->get();
        foreach ($rows as $w) {
            $this->reconcileOne($w, $driver, $credentials);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function reconcileOne(Withdrawal $w, OktoDriver $driver, array $credentials): void
    {
        if (! in_array($w->status, ['pending', 'processing'], true) || $w->payout_provider !== 'okto') {
            $this->warn('Saque ignorado (não está pending/okto).');

            return;
        }

        $tx = trim((string) $w->payout_external_id);
        if ($tx === '') {
            $tx = (string) $w->id;
        }

        try {
            $status = $driver->getTransferStatus($tx, $credentials);
        } catch (\Throwable $e) {
            $this->error('Falha ao consultar saque #'.$w->id.': '.$e->getMessage());

            return;
        }

        if ($status === 'paid') {
            MerchantWithdrawalService::markPaid($w->fresh());
            $this->info('Saque #'.$w->id.' marcado como pago.');

            return;
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            MerchantWithdrawalService::markFailed($w->fresh(), 'Payout Okto falhou (comando): '.(string) $status);
            $this->warn('Saque #'.$w->id.' marcado como falhou.');
        }
    }
}
