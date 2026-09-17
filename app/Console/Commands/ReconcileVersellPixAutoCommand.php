<?php

namespace App\Console\Commands;

use App\Services\Versell\VersellPixAutoLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileVersellPixAutoCommand extends Command
{
    protected $signature = 'versell:reconcile-pix-auto {--limit=80 : Máximo de assinaturas/pedidos por execução}';

    protected $description = 'Reconcilia recorrências e cobranças de Pix Automático da Versell (rec/cobr + retentativa).';

    public function handle(VersellPixAutoLifecycleService $lifecycle): int
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $stats = $lifecycle->reconcile($limit);
        $this->info(sprintf(
            'Versell Pix Auto: rec=%d paid=%d retries=%d cancelled=%d errors=%d',
            $stats['rec'],
            $stats['paid'],
            $stats['retries'],
            $stats['cancelled'],
            $stats['errors']
        ));

        return ($stats['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
