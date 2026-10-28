<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneAcquirerAttemptsCommand extends Command
{
    protected $signature = 'acquirer:prune-attempts {--days=30}';

    protected $description = 'Apaga a telemetria de tentativas de cobrança mais antiga que N dias (não toca em pedidos).';

    public function handle(): int
    {
        if (! Schema::hasTable('acquirer_attempts')) {
            return self::SUCCESS;
        }
        $days = max(7, (int) $this->option('days'));
        $deleted = 0;
        do {
            $ids = DB::table('acquirer_attempts')->where('created_at', '<', now()->subDays($days))->limit(5000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table('acquirer_attempts')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);
        $this->info("Tentativas apagadas: {$deleted}");

        return self::SUCCESS;
    }
}
