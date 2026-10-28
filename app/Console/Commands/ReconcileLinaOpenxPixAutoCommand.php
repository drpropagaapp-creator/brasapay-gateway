<?php

namespace App\Console\Commands;

use App\Services\LinaOpenx\LinaOpenxPixAutoLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileLinaOpenxPixAutoCommand extends Command
{
    protected $signature = 'linaopenx:reconcile-pix-auto {--limit=80 : Máximo de assinaturas/pedidos por execução}';

    protected $description = 'Reconcilia consentimentos e débitos de PIX automático da Lina OpenX.';

    public function handle(LinaOpenxPixAutoLifecycleService $lifecycle): int
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('subscriptions')) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $stats = $lifecycle->reconcile($limit);
        $this->info(sprintf(
            'Lina OpenX Pix Auto: rec=%d paid=%d retries=%d cancelled=%d errors=%d',
            $stats['rec'],
            $stats['paid'],
            $stats['retries'],
            $stats['cancelled'],
            $stats['errors']
        ));

        return ($stats['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
