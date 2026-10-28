<?php

namespace App\Services\ProductPortability;

use finfo;
use ZipArchive;

/**
 * Lê e valida um .brasapay.zip sem nunca extraí-lo para o disco.
 *
 * Defesas: nomes de entrada em lista estrita (sem `..`, barra invertida, pastas
 * arbitrárias ou symlink), limites de quantidade/tamanho/taxa de compressão
 * (zip bomb), SHA-256 de cada arquivo conferido com o manifesto e, para mídias,
 * com o próprio nome; tipo real do conteúdo conferido (não só a extensão).
 */
final class PackageReader
{
    private ZipArchive $zip;

    /** @var array<string, mixed> */
    private array $manifest = [];

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, array{sha256: string, bytes: int}> */
    private array $inventory = [];

    public static function open(string $path): self
    {
        $reader = new self;
        $reader->load($path);

        return $reader;
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return list<string> nomes dos assets presentes */
    public function assetNames(): array
    {
        return array_values(array_map(
            fn ($p) => substr($p, strlen('assets/')),
            array_filter(array_keys($this->inventory), fn ($p) => str_starts_with($p, 'assets/'))
        ));
    }

    /** Bytes de um asset já validados (hash + tipo real). */
    public function asset(string $name): string
    {
        $path = 'assets/'.$name;
        if (! isset($this->inventory[$path]) || ! PackageFormat::isAllowedEntryName($path)) {
            throw new PackageException("Mídia ausente no pacote: {$name}.");
        }

        $bytes = $this->readEntry($path, PackageFormat::MAX_ENTRY_BYTES);
        $sha = hash('sha256', $bytes);
        if (! hash_equals($this->inventory[$path]['sha256'], $sha) || ! str_starts_with($name, $sha.'.')) {
            throw new PackageException("Mídia adulterada no pacote: {$name}.");
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! in_array($mime, PackageFormat::ASSET_TYPES[$ext] ?? [], true)) {
            throw new PackageException("Tipo de arquivo não permitido: {$name}.");
        }
        if (str_starts_with($mime, 'image/') && @getimagesizefromstring($bytes) === false) {
            throw new PackageException("Imagem inválida: {$name}.");
        }

        return $bytes;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    private function load(string $path): void
    {
        if (! is_file($path) || filesize($path) > PackageFormat::MAX_PACKAGE_BYTES) {
            throw new PackageException('Pacote ausente ou maior que o limite permitido.');
        }

        $this->zip = new ZipArchive;
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new PackageException('Arquivo não é um pacote BrasaPay válido.');
        }
        if ($this->zip->numFiles < 2 || $this->zip->numFiles > PackageFormat::MAX_ENTRIES) {
            throw new PackageException('Pacote com quantidade de arquivos fora do limite.');
        }

        $total = 0;
        $names = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (! PackageFormat::isAllowedEntryName($name) || isset($names[$name])) {
                throw new PackageException('O pacote contém arquivos não permitidos.');
            }
            if ($this->isSymlink($i)) {
                throw new PackageException('O pacote contém links simbólicos.');
            }
            $size = (int) ($stat['size'] ?? 0);
            $compressed = max(1, (int) ($stat['comp_size'] ?? 0));
            if ($size > PackageFormat::MAX_ENTRY_BYTES || $size / $compressed > PackageFormat::MAX_COMPRESSION_RATIO) {
                throw new PackageException('O pacote contém um arquivo grande demais.');
            }
            $total += $size;
            if ($total > PackageFormat::MAX_TOTAL_BYTES) {
                throw new PackageException('O pacote descompactado passa do limite permitido.');
            }
            $names[$name] = true;
        }

        if (! isset($names[PackageFormat::MANIFEST], $names[PackageFormat::DATA])) {
            throw new PackageException('Pacote incompleto: manifesto ou dados ausentes.');
        }

        $manifest = json_decode($this->readEntry(PackageFormat::MANIFEST, 2 * 1024 * 1024), true);
        if (! is_array($manifest)
            || ($manifest['format'] ?? null) !== PackageFormat::FORMAT
            || ! is_int($manifest['schema_version'] ?? null)
            || (int) ($manifest['min_schema_version'] ?? 1) > PackageFormat::SCHEMA_VERSION
            || ! is_array($manifest['inventory'] ?? null)) {
            throw new PackageException('Manifesto inválido ou de uma versão não suportada.');
        }

        foreach ($manifest['inventory'] as $item) {
            $p = (string) ($item['path'] ?? '');
            $sha = (string) ($item['sha256'] ?? '');
            if (! PackageFormat::isAllowedEntryName($p) || ! preg_match('/^[a-f0-9]{64}$/', $sha) || ! isset($names[$p])) {
                throw new PackageException('Inventário do manifesto não confere com o pacote.');
            }
            $this->inventory[$p] = ['sha256' => $sha, 'bytes' => (int) ($item['bytes'] ?? 0)];
        }
        foreach (array_keys($names) as $name) {
            if ($name !== PackageFormat::MANIFEST && ! isset($this->inventory[$name])) {
                throw new PackageException('O pacote contém arquivos fora do manifesto.');
            }
        }

        $json = $this->readEntry(PackageFormat::DATA, PackageFormat::MAX_DATA_BYTES);
        if (! hash_equals($this->inventory[PackageFormat::DATA]['sha256'], hash('sha256', $json))) {
            throw new PackageException('Os dados do pacote foram alterados depois de gerados.');
        }
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['products'] ?? null) || $data['products'] === []
            || count($data['products']) > PackageFormat::MAX_PRODUCTS) {
            throw new PackageException('Dados do pacote inválidos.');
        }

        $this->manifest = $manifest;
        $this->data = $data;
    }

    private function readEntry(string $name, int $max): string
    {
        $stream = $this->zip->getStream($name);
        if ($stream === false) {
            throw new PackageException("Arquivo ilegível no pacote: {$name}.");
        }
        $bytes = '';
        while (! feof($stream)) {
            $bytes .= (string) fread($stream, 65536);
            if (strlen($bytes) > $max) {
                fclose($stream);
                throw new PackageException("Arquivo maior que o declarado no pacote: {$name}.");
            }
        }
        fclose($stream);

        return $bytes;
    }

    private function isSymlink(int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (! $this->zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000;
    }
}
