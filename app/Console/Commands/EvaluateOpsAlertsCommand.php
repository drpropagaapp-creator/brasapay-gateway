<?php

namespace App\Console\Commands;

use App\Services\Platform\CommandCenterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Recalcula os alertas do Command Center a partir de números reais e grava abertura/resolução. */
class EvaluateOpsAlertsCommand extends Command
{
    protected $signature = 'ops:evaluate-alerts';

    protected $description = 'Atualiza a central de alertas do Command Center';

    public function handle(CommandCenterService $center): int
    {
        $snapshot = $center->snapshot();
        [$opened, $resolved] = $center->syncAlerts($snapshot['alerts']);
        Cache::put('command_center:v2', $snapshot, 60);
        $this->info("Alertas: {$opened} novo(s), {$resolved} resolvido(s), ".count($snapshot['alerts']).' ativo(s).');

        return self::SUCCESS;
    }
}
