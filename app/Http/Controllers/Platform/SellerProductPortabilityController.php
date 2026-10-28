<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Jobs\ImportProductPackageJob;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Services\ProductPortability\PackageException;
use App\Services\ProductPortability\PackageFormat;
use App\Services\ProductPortability\PackageReader;
use App\Services\ProductPortability\ProductExporter;
use App\Services\ProductPortability\ProductImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exportar/importar produtos em nome de um seller — exclusivo do admin, com step-up e auditoria.
 */
class SellerProductPortabilityController extends Controller
{
    use RequiresPlatformStepUp;

    public function index(User $user): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);
        $tenantId = $this->tenantIdFor($user);

        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'type', 'is_active'])
            ->map(fn (Product $p) => [
                'id' => (string) $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'is_active' => (bool) $p->is_active,
            ]);

        $imports = ProductImport::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (ProductImport $i) => $this->present($i));

        return response()->json([
            'products' => $products,
            'imports' => $imports,
            'max_mb' => (int) (PackageFormat::MAX_PACKAGE_BYTES / 1024 / 1024),
        ]);
    }

    public function export(Request $request, User $user, Product $produto, ProductExporter $exporter): BinaryFileResponse|JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);
        $this->validatePlatformStepUp($request);
        $tenantId = $this->tenantIdFor($user);
        if ((int) $produto->tenant_id !== $tenantId) {
            abort(404);
        }

        $validated = $request->validate([
            'dependencies' => ['nullable', 'array', 'max:'.(PackageFormat::MAX_PRODUCTS - 1)],
            'dependencies.*' => ['string', 'max:64'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $dependencies = array_map('strval', $validated['dependencies'] ?? []);

        try {
            $path = $exporter->export($produto, $dependencies);
        } catch (PackageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        PlatformAuditService::log('platform.seller.product_exported', [
            'seller_user_id' => $user->id,
            'tenant_id' => $tenantId,
            'product_id' => (string) $produto->id,
            'dependencies' => $dependencies,
            'reason' => $validated['reason'],
        ], $request);

        $name = (Str::slug($produto->name) ?: 'produto').'.brasapay.zip';

        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    public function upload(Request $request, User $user, ProductImporter $importer): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);
        $tenantId = $this->tenantIdFor($user);
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
            'package_sha256' => hash_file('sha256', Storage::disk('local')->path($relative)),
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
        } catch (\Throwable $e) {
            if (! $e instanceof PackageException) {
                Log::error('ProductImport (plataforma): validação falhou', ['import' => $uuid, 'message' => $e->getMessage()]);
            }
            $this->discardPackage($import);
            $import->update([
                'status' => ProductImport::STATUS_FAILED,
                'error' => $e instanceof PackageException ? $e->getMessage() : 'Não foi possível ler este pacote.',
            ]);
        }

        return response()->json($this->present($import->fresh()));
    }

    public function confirm(Request $request, User $user, ProductImport $import): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);
        $this->validatePlatformStepUp($request);
        $tenantId = $this->tenantIdFor($user);
        if ((int) $import->tenant_id !== $tenantId) {
            abort(404);
        }
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $claimed = ProductImport::query()
            ->whereKey($import->id)
            ->where('status', ProductImport::STATUS_READY)
            ->update(['status' => ProductImport::STATUS_IMPORTING, 'updated_at' => now()]);

        if ($claimed === 1) {
            PlatformAuditService::log('platform.seller.product_imported', [
                'seller_user_id' => $user->id,
                'tenant_id' => $tenantId,
                'import' => $import->uuid,
                'package_sha256' => $import->package_sha256,
                'reason' => $validated['reason'],
            ], $request);
            ImportProductPackageJob::dispatch($import->id);
        }

        return response()->json($this->present($import->fresh()));
    }

    public function status(User $user, ProductImport $import): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);
        if ((int) $import->tenant_id !== $this->tenantIdFor($user)) {
            abort(404);
        }

        return response()->json($this->present($import->fresh()));
    }

    public function cancel(User $user, ProductImport $import): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);
        if ((int) $import->tenant_id !== $this->tenantIdFor($user)) {
            abort(404);
        }

        $cancelled = ProductImport::query()
            ->whereKey($import->id)
            ->where('status', ProductImport::STATUS_READY)
            ->update(['status' => ProductImport::STATUS_CANCELLED, 'updated_at' => now()]);
        if ($cancelled === 1) {
            $this->discardPackage($import);
            $import->save();
        }

        return response()->json($this->present($import->fresh()));
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
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    private function tenantIdFor(User $user): int
    {
        if (! $user->isInfoprodutor()) {
            abort(422, 'Portabilidade de produtos só se aplica a contas de infoprodutor.');
        }

        return (int) ($user->tenant_id ?? $user->id);
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
