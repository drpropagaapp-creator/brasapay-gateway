<?php

namespace App\Console\Commands;

use App\Services\Recovery\BrasaRecoveryService;
use Illuminate\Console\Command;

class RecoveryProcessEmailCommand extends Command
{
    protected $signature = 'recovery:process-email';

    protected $description = 'Brasa Recovery: envia e-mails de recuperação (carrinho, PIX, boleto) e atribui vendas recuperadas.';

    public function handle(BrasaRecoveryService $recovery): int
    {
        $r = $recovery->run();
        $this->info("Recovery e-mail: {$r['sent']} enviados, {$r['skipped']} pulados, {$r['failed']} falhas, {$r['recovered']} recuperações atribuídas.");

        return self::SUCCESS;
    }
}
