<?php

namespace App\Services\ProductPortability;

/**
 * Formato .brasapay.zip (ZIP padrão):
 *
 *   manifest.json            formato, versão, inventário com SHA-256 de cada arquivo
 *   data/package.json        produtos, ofertas, planos, order bumps, checkout, landing,
 *                            upsell/downsell, área de membros, modelo de coprodução
 *   assets/<sha256>.<ext>    mídias (o nome É o hash do conteúdo)
 *
 * Referências entre recursos usam chaves locais do pacote ("p1", "p1.o3"…); IDs,
 * tenant, credenciais, pixels com token e dados de compradores nunca entram.
 */
final class PackageFormat
{
    public const FORMAT = 'brasapay.product-package';

    public const SCHEMA_VERSION = 1;

    public const MANIFEST = 'manifest.json';

    public const DATA = 'data/package.json';

    public const ASSET_PREFIX = 'asset://';

    public const MAX_PACKAGE_BYTES = 200 * 1024 * 1024;

    public const MAX_ENTRIES = 1500;

    public const MAX_ENTRY_BYTES = 30 * 1024 * 1024;

    public const MAX_DATA_BYTES = 20 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 400 * 1024 * 1024;

    /** Taxa máxima de compressão por arquivo (barreira contra zip bomb). */
    public const MAX_COMPRESSION_RATIO = 100;

    public const MAX_PRODUCTS = 25;

    /** extensão => famílias de MIME aceitas (conferidas pelo conteúdo real) */
    public const ASSET_TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv'],
        'rtf' => ['text/rtf', 'application/rtf', 'text/plain'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
    ];

    /** Nome de entrada permitido no ZIP (sem pastas arbitrárias, sem `..`, sem barra invertida). */
    public static function isAllowedEntryName(string $name): bool
    {
        if ($name === self::MANIFEST || $name === self::DATA) {
            return true;
        }

        return (bool) preg_match('#^assets/[a-f0-9]{64}\.('.implode('|', array_keys(self::ASSET_TYPES)).')$#', $name);
    }

    public static function assetPlaceholder(string $assetName): string
    {
        return self::ASSET_PREFIX.$assetName;
    }

    public static function brasapayVersion(): string
    {
        $file = base_path('VERSION');

        return is_file($file) ? trim((string) file_get_contents($file)) : 'unknown';
    }
}
