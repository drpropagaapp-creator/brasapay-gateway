<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class MemberLessonMaterialUpload
{
    public const REJECTION_MESSAGE = 'Formato não permitido. Use PDF, TXT, CSV, RTF, DOCX, XLSX, PPTX, ODT, ODS ou ODP.';

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = [
        'pdf',
        'txt',
        'csv',
        'rtf',
        'docx',
        'xlsx',
        'pptx',
        'odt',
        'ods',
        'odp',
    ];

    /** @var array<string, string> */
    private const SERVE_MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'rtf' => 'application/rtf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED_MIMES_BY_EXTENSION = [
        'pdf' => ['application/pdf', 'application/x-pdf'],
        'txt' => ['text/plain', 'text/csv'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'rtf' => ['application/rtf', 'text/rtf', 'text/plain'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'odt' => [
            'application/vnd.oasis.opendocument.text',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'ods' => [
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'odp' => [
            'application/vnd.oasis.opendocument.presentation',
            'application/zip',
            'application/x-zip-compressed',
        ],
    ];

    /** @var array<string, string> */
    private const ZIP_REQUIRED_ENTRY = [
        'docx' => 'word/document.xml',
        'xlsx' => 'xl/workbook.xml',
        'pptx' => 'ppt/presentation.xml',
    ];

    /** @var array<string, string> */
    private const ODF_MIMETYPE = [
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
    ];

    /** @var list<string> */
    private const DANGEROUS_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'shtml', 'mhtml',
        'svg', 'svgz',
        'xml', 'xsl', 'xslt',
        'js', 'mjs', 'cjs', 'css', 'wasm',
        'php', 'phtml', 'php3', 'php4', 'php5', 'phar',
        'asp', 'aspx', 'jsp', 'cgi', 'htaccess',
        'hta', 'url', 'lnk', 'webloc', 'wsf', 'vbs',
        'exe', 'dll', 'bat', 'cmd', 'ps1', 'sh', 'msi', 'apk',
        'zip', 'rar', '7z', 'gz', 'tgz', 'iso', 'img',
        'doc', 'xls', 'ppt',
        'docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'xlsb', 'pptm', 'potm', 'ppam',
        'swf', 'eml', 'msg',
    ];

    /** @var list<string> */
    private const DANGEROUS_ZIP_ENTRY_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'js', 'mjs', 'php', 'phtml',
        'exe', 'bat', 'cmd', 'hta', 'wsf', 'vbs', 'dll',
    ];

    /**
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }

    public static function isAllowedExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::ALLOWED_EXTENSIONS, true);
    }

    public static function htmlAccept(): string
    {
        $extensions = array_map(static fn (string $ext): string => '.'.$ext, self::ALLOWED_EXTENSIONS);

        return implode(',', array_merge($extensions, [
            'application/pdf',
            'text/plain',
            'text/csv',
            'application/rtf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
        ]));
    }

    public static function serveMimeForExtension(string $extension): string
    {
        return self::SERVE_MIME_BY_EXTENSION[strtolower($extension)] ?? 'application/octet-stream';
    }

    public static function attachmentDisposition(string $filename): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($filename)) ?: 'material';

        return 'attachment; filename="'.$safe.'"';
    }

    /**
     * @return array{extension: string, mime: string}
     */
    public static function assertValid(UploadedFile $file, string $field = 'file'): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                $field => UploadLimits::messageForPhpUploadError($file->getError(), UploadLimits::memberBuilderPdfMaxMb()),
            ]);
        }

        $original = (string) $file->getClientOriginalName();
        self::assertOriginalNameSafe($original, $field);

        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        if (! self::isAllowedExtension($extension)) {
            throw ValidationException::withMessages([
                $field => self::REJECTION_MESSAGE,
            ]);
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw ValidationException::withMessages([
                $field => 'Não foi possível ler o arquivo enviado.',
            ]);
        }

        if ((int) $file->getSize() <= 0) {
            throw ValidationException::withMessages([
                $field => 'O arquivo enviado está vazio.',
            ]);
        }

        $mime = self::normalizeMime($file);
        $allowedMimes = self::ALLOWED_MIMES_BY_EXTENSION[$extension];
        if ($mime !== '' && ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                $field => self::REJECTION_MESSAGE,
            ]);
        }

        $head = (string) @file_get_contents($path, false, null, 0, 4096);
        self::assertNotExecutableMarkup($head, $field);

        match ($extension) {
            'pdf' => self::assertPdf($head, $field),
            'rtf' => self::assertRtf($head, $field),
            'txt', 'csv' => null,
            'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp' => self::assertOfficeContainer($path, $extension, $field),
            default => throw ValidationException::withMessages([$field => self::REJECTION_MESSAGE]),
        };

        return [
            'extension' => $extension,
            'mime' => self::SERVE_MIME_BY_EXTENSION[$extension],
        ];
    }

    public static function safeStoredName(string $originalName, string $extension): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $base) ?? 'material';
        $base = trim($base, '._-');
        if ($base === '' || ! preg_match('/[a-zA-Z0-9]/', $base)) {
            $base = 'material';
        }

        return $base.'_'.bin2hex(random_bytes(4)).'.'.$extension;
    }

    /**
     * @return array<string, string>
     */
    public static function storageUploadOptions(string $storedName, string $mime): array
    {
        return [
            'mimetype' => $mime,
            'ContentType' => $mime,
            'ContentDisposition' => self::attachmentDisposition($storedName),
        ];
    }

    private static function assertOriginalNameSafe(string $original, string $field): void
    {
        $lower = strtolower($original);
        if ($lower === '' || str_contains($lower, "\0")) {
            throw ValidationException::withMessages([
                $field => self::REJECTION_MESSAGE,
            ]);
        }

        $pattern = '/\.('.implode('|', array_map(static fn (string $ext): string => preg_quote($ext, '/'), self::DANGEROUS_EXTENSIONS)).')\./i';
        if (preg_match($pattern, $lower) === 1) {
            throw ValidationException::withMessages([
                $field => self::REJECTION_MESSAGE,
            ]);
        }
    }

    private static function normalizeMime(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        if (! is_string($mime) || $mime === '' || $mime === 'application/octet-stream') {
            return '';
        }

        return strtolower(trim(explode(';', $mime)[0]));
    }

    private static function assertNotExecutableMarkup(string $head, string $field): void
    {
        $trim = ltrim($head, "\xEF\xBB\xBF \t\n\r");
        $lower = strtolower($trim);

        $prefixes = [
            '<!doctype html',
            '<html',
            '<svg',
            '<script',
            '<?php',
            '<iframe',
            '<embed',
            '<object',
            '<hta:',
        ];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                throw ValidationException::withMessages([
                    $field => self::REJECTION_MESSAGE,
                ]);
            }
        }

        if (str_starts_with($lower, '<?xml') && (str_contains($lower, '<svg') || str_contains($lower, '<html'))) {
            throw ValidationException::withMessages([
                $field => self::REJECTION_MESSAGE,
            ]);
        }
    }

    private static function assertPdf(string $head, string $field): void
    {
        if (! str_starts_with($head, '%PDF-')) {
            throw ValidationException::withMessages([
                $field => 'O arquivo não é um PDF válido.',
            ]);
        }
    }

    private static function assertRtf(string $head, string $field): void
    {
        $trim = ltrim($head);
        if (! str_starts_with($trim, '{\\rtf')) {
            throw ValidationException::withMessages([
                $field => 'O arquivo não é um RTF válido.',
            ]);
        }
    }

    private static function assertOfficeContainer(string $path, string $extension, string $field): void
    {
        $header = (string) @file_get_contents($path, false, null, 0, 4);
        if ($header !== "PK\x03\x04" && $header !== "PK\x05\x06" && $header !== "PK\x07\x08") {
            throw ValidationException::withMessages([
                $field => self::REJECTION_MESSAGE,
            ]);
        }

        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages([
                $field => 'Não foi possível validar o documento no servidor.',
            ]);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([
                $field => self::REJECTION_MESSAGE,
            ]);
        }

        try {
            self::assertZipEntriesSafe($zip, $field);
            self::assertNoOfficeMacros($zip, $field);

            if (isset(self::ZIP_REQUIRED_ENTRY[$extension])) {
                if ($zip->locateName(self::ZIP_REQUIRED_ENTRY[$extension]) === false) {
                    throw ValidationException::withMessages([
                        $field => self::REJECTION_MESSAGE,
                    ]);
                }

                return;
            }

            $expected = self::ODF_MIMETYPE[$extension] ?? null;
            if ($expected === null) {
                throw ValidationException::withMessages([
                    $field => self::REJECTION_MESSAGE,
                ]);
            }

            $declared = strtolower(trim((string) $zip->getFromName('mimetype')));
            if ($declared !== $expected) {
                throw ValidationException::withMessages([
                    $field => self::REJECTION_MESSAGE,
                ]);
            }
        } finally {
            $zip->close();
        }
    }

    private static function assertZipEntriesSafe(ZipArchive $zip, string $field): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_contains($name, '..')) {
                throw ValidationException::withMessages([
                    $field => self::REJECTION_MESSAGE,
                ]);
            }

            $entryExt = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if ($entryExt !== '' && in_array($entryExt, self::DANGEROUS_ZIP_ENTRY_EXTENSIONS, true)) {
                throw ValidationException::withMessages([
                    $field => self::REJECTION_MESSAGE,
                ]);
            }
        }
    }

    private static function assertNoOfficeMacros(ZipArchive $zip, string $field): void
    {
        $macroMarkers = [
            'word/vbaProject.bin',
            'xl/vbaProject.bin',
            'ppt/vbaProject.bin',
            'vbaProject.bin',
        ];
        foreach ($macroMarkers as $marker) {
            if ($zip->locateName($marker, ZipArchive::FL_NOCASE) !== false) {
                throw ValidationException::withMessages([
                    $field => 'Arquivos com macro não são permitidos.',
                ]);
            }
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = strtolower(str_replace('\\', '/', (string) $zip->getNameIndex($i)));
            if (str_contains($name, 'vbaproject') || str_starts_with($name, 'basic/')) {
                throw ValidationException::withMessages([
                    $field => 'Arquivos com macro não são permitidos.',
                ]);
            }
        }
    }
}
