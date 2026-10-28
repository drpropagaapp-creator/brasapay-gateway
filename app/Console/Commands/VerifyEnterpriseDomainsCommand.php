<?php

namespace App\Console\Commands;

use App\Models\EnterpriseDomain;
use App\Services\Enterprise\EnterpriseService;
use Illuminate\Console\Command;

/** Reverifica diariamente os domínios white-label ativos; quem perdeu TXT ou HTTPS é desativado após falhas seguidas. */
class VerifyEnterpriseDomainsCommand extends Command
{
    protected $signature = 'enterprise:verify-domains';

    protected $description = 'Reverifica DNS e HTTPS dos domínios white-label ativos';

    public function handle(EnterpriseService $enterprise): int
    {
        $checked = 0;
        EnterpriseDomain::query()->where('status', EnterpriseDomain::STATUS_ACTIVE)->orderBy('id')->each(function (EnterpriseDomain $d) use ($enterprise, &$checked) {
            $enterprise->recheck($d);
            $checked++;
        });
        $this->info("Domínios reverificados: {$checked}.");

        return self::SUCCESS;
    }
}
