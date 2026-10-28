<?php

namespace App\Console\Commands;

use App\Models\ApiRequestLog;
use App\Models\SandboxTransaction;
use Illuminate\Console\Command;

/** Retenção do Brasa Developers: registros de chamadas (padrão 30 dias) e transações de sandbox (90 dias). */
class PruneDeveloperDataCommand extends Command
{
    protected $signature = 'developers:prune {--days=30} {--sandbox-days=90}';

    protected $description = 'Apaga registros antigos de chamadas da API e transações de sandbox';

    public function handle(): int
    {
        $logs = 0;
        do {
            $deleted = ApiRequestLog::query()->where('created_at', '<', now()->subDays(max(1, (int) $this->option('days'))))->limit(5000)->delete();
            $logs += $deleted;
        } while ($deleted === 5000);

        $sandbox = SandboxTransaction::query()->where('created_at', '<', now()->subDays(max(1, (int) $this->option('sandbox-days'))))->delete();
        $this->info("Chamadas removidas: {$logs}. Transações de sandbox removidas: {$sandbox}.");

        return self::SUCCESS;
    }
}
