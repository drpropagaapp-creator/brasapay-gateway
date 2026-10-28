<?php

namespace App\Console\Commands;

use App\Services\Risk\BrasaShieldService;
use Illuminate\Console\Command;

class ShieldScanCommand extends Command
{
    protected $signature = 'shield:scan {--minutes=30 : Pedidos e saques criados nos últimos N minutos}';

    protected $description = 'BrasaShield: calcula o score de risco de pedidos e saques recentes (somente observação).';

    public function handle(BrasaShieldService $shield): int
    {
        $r = $shield->scan(max(5, (int) $this->option('minutes')));
        $this->info("Avaliados: {$r['orders']} pedidos, {$r['withdrawals']} saques.");

        return self::SUCCESS;
    }
}
