<?php

namespace App\Http\Controllers;

use App\Jobs\ImportProductPackageJob;
use App\Models\Product;
use App\Models\ProductImport;
use App\Services\ProductPortability\PackageException;
use App\Services\ProductPortability\PackageFormat;
use App\Services\ProductPortability\PackageReader;
use App\Services\ProductPortability\ProductExporter;
use App\Services\ProductPortability\ProductImporter;
use App\Services\TeamAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exportar um produto para um pacote .brasapay.zip e importar pacotes na conta.
 */
class ProductPortabilityController extends Controller
{
    public function exportOptions(Product $produto, ProductExporter $exporter): JsonResponse
    {
        $this->authorizeExport($produto);

        return response()->json([
            'product' => ['id' => (string) $produto->id, 'name' => $produto->name],
            'dependencies' => $exporter->dependencyCandidates($produto),
        ]);
    }

    public function export(Request $request, Product $produto, ProductExporter $exporter): BinaryFileResponse|RedirectResponse
    {
        $this->authorizeExport($produto);
        $validated = $request->validate([
            'dependencies' => ['nullable', 'array', 'max:'.(PackageFormat::MAX_PRODUCTS - 1)],
            'dependencies.*' => ['string', 'max:64'],
        ]);

        try {
            $path = $exporter->export($produto, $validated['dependencies'] ?? []);
        } catch (PackageException $e) {
            return back()->with('error', $e->getMessage());
        }

        Log::info('ProductExport: download', [
            'tenant_id' => $produto->tenant_id,
            'user_id' => $request->user()->id,
            'product_id' => $produto->id,
            'dependencies' => $validated['dependencies'] ?? [],
        ]);

        $name = (Str::slug($produto->name) ?: 'produto').'.brasapay.zip';

        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    public function create(): Response
    {
        $this->authorizeImport();

        return Inertia::render('Produtos/Importar', [
            'import' => null,
            'max_mb' => (int) (PackageFormat::MAX_PACKAGE_BYTES / 1024 / 1024),
        ]);
    }

    public function upload(Request $request, ProductImporter $importer): RedirectResponse
    {
        $tenantId = $this->authorizeImport();
        $request->validate([
            'package' => ['required', 'file', 'max:'.(int) (PackageFormat::MAX_PACKAGE_BYTES / 1024)],
        ]);

        $file = $request->file('package');
        $uuid = (string) Str::uuid();
        $relative = 'product-imports/'.$uuid.'.zip';
        $file->storeAs('product-imports', $uuid.'.zip', 'local');

        $import = ProductImport::query()->create([
            'uuid' => $uuid,
            'tenant_id' => $tenantId,
            'user_id' => $request->user()->id,
            'status' => ProductImport::STATUS_VALIDATING,
            'package_path' => $relative,
            'package_sha256' => hash_file('sha256', \Illuminate\Support\Facades\Storage::disk('local')->path($relative)),
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
        ]);

        try {
            $reader = PackageReader::open($import->absolutePackagePath());
            try {
                $summary = $importer->preview($reader, $tenantId);
            } finally {
                $reader->close();
            }
            $import->update(['status' => ProductImport::STATUS_READY, 'summary' => $summary]);
        } catch (PackageException $e) {
            $this->discardPackage($import);
            $import->update(['status' => ProductImport::STATUS_FAILED, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('ProductImport: validação falhou', ['import' => $uuid, 'message' => $e->getMessage()]);
            $this->discardPackage($import);
            $import->update(['status' => ProductImport::STATUS_FAILED, 'error' => 'Não foi possível ler este pacote.']);
        }

        Log::info('ProductImport: pacote enviado', ['import' => $uuid, 'tenant_id' => $tenantId, 'status' => $import->status]);

        return redirect()->route('produtos.import.show', $import);
    }

    public function show(ProductImport $import): Response
    {
        $this->authorizeImportRow($import);

        return Inertia::render('Produtos/Importar', [
            'import' => $this->present($import),
            'max_mb' => (int) (PackageFormat::MAX_PACKAGE_BYTES / 1024 / 1024),
        ]);
    }

    public function status(ProductImport $import): JsonResponse
    {
        $this->authorizeImportRow($import);

        return response()->json($this->present($import->fresh()));
    }

    public function confirm(ProductImport $import): RedirectResponse
    {
        $this->authorizeImportRow($import);

        // Transição atômica: um clique duplo (ou dois navegadores) não importa duas vezes.
        $claimed = ProductImport::query()
            ->whereKey($import->id)
            ->where('status', ProductImport::STATUS_READY)
            ->update(['status' => ProductImport::STATUS_IMPORTING, 'updated_at' => now()]);

        if ($claimed === 1) {
            ImportProductPackageJob::dispatch($import->id);
        }

        return redirect()->route('produtos.import.show', $import);
    }

    public function cancel(ProductImport $import): RedirectResponse
    {
        $this->authorizeImportRow($import);

        $cancelled = ProductImport::query()
            ->whereKey($import->id)
            ->where('status', ProductImport::STATUS_READY)
            ->update(['status' => ProductImport::STATUS_CANCELLED, 'updated_at' => now()]);
        if ($cancelled === 1) {
            $this->discardPackage($import);
            $import->save();
        }

        return redirect()->route('produtos.index')->with('success', 'Importação cancelada.');
    }

    /** @return array<string, mixed> */
    private function present(ProductImport $import): array
    {
        return [
            'uuid' => $import->uuid,
            'status' => $import->status,
            'original_name' => $import->original_name,
            'summary' => $import->summary,
            'result' => $import->result,
            'error' => $import->error,
        ];
    }

    private function authorizeExport(Product $produto): void
    {
        $user = auth()->user();
        if ((int) $produto->tenant_id !== (int) $user->tenant_id) {
            abort(403);
        }
        if ($user->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
            if (! in_array((string) $produto->id, array_map('strval', $allowed), true)) {
                abort(403);
            }
        }
    }

    private function authorizeImport(): int
    {
        $user = auth()->user();
        // Importar cria produtos na conta: só o titular (equipe não importa).
        if ($user === null || $user->isTeam() || ! $user->isInfoprodutor() || (int) $user->tenant_id < 1) {
            abort(403);
        }

        return (int) $user->tenant_id;
    }

    private function authorizeImportRow(ProductImport $import): void
    {
        $tenantId = $this->authorizeImport();
        if ((int) $import->tenant_id !== $tenantId) {
            abort(404);
        }
    }

    private function discardPackage(ProductImport $import): void
    {
        $path = $import->absolutePackagePath();
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
        $import->package_path = null;
    }
}
