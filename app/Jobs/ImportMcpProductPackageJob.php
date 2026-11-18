<?php

namespace App\Jobs;

use App\Models\McpProductImport;
use App\Services\Mcp\McpProductPackageImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Importação de pacote de produto (zip) pedida pelo MCP da agência.
 * Roda na fila "imports" (worker próprio): baixar e subir vídeos leva minutos e
 * não pode segurar a fila de pagamentos.
 */
class ImportMcpProductPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(McpProductPackageImporter $importer): void
    {
        $import = McpProductImport::query()->find($this->importId);
        if ($import !== null) {
            $importer->run($import);
        }
    }
}
