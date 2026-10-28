<?php

namespace App\Console\Commands;

use App\Services\Treasury\TreasuryReconciliationService;
use Illuminate\Console\Command;

class TreasuryReconcileCommand extends Command
{
    protected $signature = 'treasury:reconcile {--days=90 : Janela de dias analisada}';

    protected $description = 'Brasa Treasury: concilia pedidos, carteiras, MED e saques e registra divergências (somente observação).';

    public function handle(): int
    {
        $result = (new TreasuryReconciliationService((int) $this->option('days')))->run();
        if (! empty($result['skipped'])) {
            $this->warn('Conciliação já em execução ou tabelas ausentes — nada feito.');

            return self::SUCCESS;
        }

        $counts = $result['counts'];
        $this->info(sprintf('Conciliação #%d: %d novas, %d encerradas automaticamente.', $result['run_id'], $counts['new'] ?? 0, $counts['auto_resolved'] ?? 0));

        return self::SUCCESS;
    }
}
