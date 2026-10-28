<?php

namespace App\Services\ProductPortability;

use App\Services\StorageService;
use Illuminate\Support\Facades\Log;

/**
 * Leva para o pacote só mídias que pertencem aos produtos exportados.
 *
 * Uma URL/caminho só vira arquivo do pacote se a chave no storage do tenant de
 * ORIGEM estiver num prefixo do próprio produto (checkout/<id>/, member-area/<id>/…)
 * ou for a imagem principal do produto. Qualquer outra URL fica como link externo
 * — assim o export não vira um jeito de copiar arquivos de outra conta.
 */
final class AssetCollector
{
    /** @var array<string, array{name: string, bytes: string}> nome => conteúdo */
    private array $assets = [];

    /** @var array<string, string|null> chave de storage => nome do asset (ou null se ilegível) */
    private array $byKey = [];

    /** @var list<string> */
    private array $allowedPrefixes = [];

    /** @var array<string, true> chaves liberadas individualmente (imagem principal) */
    private array $allowedKeys = [];

    private int $totalBytes = 0;

    public function __construct(private StorageService $storage) {}

    /** @param  list<int|string>  $productIds */
    public function allowProducts(array $productIds): void
    {
        foreach ($productIds as $id) {
            foreach (['checkout', 'member-area', 'member-area-gamification', 'email-templates'] as $root) {
                $this->allowedPrefixes[] = $root.'/'.$id.'/';
            }
        }
    }

    public function allowKey(?string $key): void
    {
        if ($key !== null && $key !== '') {
            $this->allowedKeys[$key] = true;
        }
    }

    /** Caminho relativo (coluna de imagem) → placeholder ou null se não pôde ser embalado. */
    public function collectPath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }
        $name = $this->collectKey($this->storage->toStoragePath($path));

        return $name !== null ? PackageFormat::assetPlaceholder($name) : null;
    }

    /**
     * Troca, dentro de um texto (URL pura ou HTML), as URLs de mídia própria por
     * placeholders. Links externos permanecem como estão.
     */
    public function rewriteString(string $value): string
    {
        if ($value === '' || ! str_contains($value, '://')) {
            return $value;
        }

        return (string) preg_replace_callback('#https?://[^\s"\'<>()]+#i', function (array $m) {
            $name = $this->collectKey($this->storage->toStoragePath($m[0]));

            return $name !== null ? PackageFormat::assetPlaceholder($name) : $m[0];
        }, $value);
    }

    /** Aplica rewriteString em toda string de uma estrutura. */
    public function rewriteTree(mixed $data): mixed
    {
        if (is_string($data)) {
            return $this->rewriteString($data);
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->rewriteTree($v);
            }
        }

        return $data;
    }

    /** @return array<string, array{name: string, bytes: string}> */
    public function assets(): array
    {
        return $this->assets;
    }

    private function collectKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        if (array_key_exists($key, $this->byKey)) {
            return $this->byKey[$key];
        }

        $this->byKey[$key] = null;
        if (! $this->isAllowed($key)) {
            return null;
        }

        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        if (! array_key_exists($ext, PackageFormat::ASSET_TYPES)) {
            return null;
        }

        try {
            $disk = $this->storage->disk();
            if (! $disk->exists($key)) {
                return null;
            }
            $bytes = $disk->get($key);
        } catch (\Throwable $e) {
            Log::warning('ProductExport: mídia ilegível ignorada', ['key' => $key, 'message' => $e->getMessage()]);

            return null;
        }

        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > PackageFormat::MAX_ENTRY_BYTES) {
            return null;
        }
        if ($this->totalBytes + strlen($bytes) > PackageFormat::MAX_TOTAL_BYTES) {
            throw new PackageException('O produto tem mídias demais para um único pacote.');
        }

        $name = hash('sha256', $bytes).'.'.$ext;
        if (! isset($this->assets[$name])) {
            $this->assets[$name] = ['name' => $name, 'bytes' => $bytes];
            $this->totalBytes += strlen($bytes);
        }

        return $this->byKey[$key] = $name;
    }

    private function isAllowed(string $key): bool
    {
        if (str_contains($key, '..')) {
            return false;
        }
        if (isset($this->allowedKeys[$key])) {
            return true;
        }
        foreach ($this->allowedPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
