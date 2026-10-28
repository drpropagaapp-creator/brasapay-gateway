<?php

namespace App\Jobs;

use App\Models\ProductImport;
use App\Services\ProductPortability\PackageException;
use App\Services\ProductPortability\PackageReader;
use App\Services\ProductPortability\ProductImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Executa uma importação confirmada. Idempotente: só roda se a importação ainda
 * estiver em `importing` e sem resultado — reenvio do job não duplica produtos.
 */
class ImportProductPackageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $importId) {}

    public function handle(ProductImporter $importer): void
    {
        $import = ProductImport::query()->find($this->importId);
        if ($import === null || $import->status !== ProductImport::STATUS_IMPORTING || $import->result !== null) {
            return;
        }

        $path = $import->absolutePackagePath();
        try {
            if ($path === null || ! is_file($path) || ! hash_equals((string) $import->package_sha256, (string) hash_file('sha256', $path))) {
                throw new PackageException('O pacote enviado não está mais disponível. Envie de novo.');
            }
            $reader = PackageReader::open($path);
            try {
                $result = $importer->import($reader, (int) $import->tenant_id, $import->user_id ? (int) $import->user_id : null);
            } finally {
                $reader->close();
            }

            $import->update(['status' => ProductImport::STATUS_COMPLETED, 'result' => $result, 'error' => null]);
        } catch (\Throwable $e) {
            $message = $e instanceof PackageException ? $e->getMessage() : 'Não foi possível concluir a importação. Nada foi criado na sua conta.';
            Log::error('ProductImport: falha', ['import' => $import->uuid, 'message' => $e->getMessage()]);
            $import->update(['status' => ProductImport::STATUS_FAILED, 'error' => $message]);
        } finally {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
