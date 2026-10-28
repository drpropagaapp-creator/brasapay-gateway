<?php

namespace App\Support;

use App\Services\StorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Área com desenho de um logo (recorte da margem transparente), calculada no servidor.
 *
 * O PnLogoImage fazia esse recorte no navegador lendo os pixels num canvas; com o arquivo
 * num storage de outro domínio (R2/S3 sem CORS) a leitura é bloqueada e o logo quadrado
 * (ex.: 400×400 com o desenho numa faixa) aparecia minúsculo. Aqui o arquivo é lido direto
 * do storage — sem HTTP — e o resultado fica em cache por URL (a URL muda a cada upload).
 */
class LogoTrimBox
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MAX_PIXELS = 4_000_000;

    /**
     * @param  array<int, mixed>  $urls
     * @return array<string, array{x:int,y:int,w:int,h:int,nw:int,nh:int}>
     */
    public static function boxes(array $urls): array
    {
        $out = [];
        foreach (array_unique(array_filter(array_map(fn ($u) => is_string($u) ? trim($u) : '', $urls))) as $url) {
            try {
                $box = self::for($url);
            } catch (\Throwable $e) {
                Log::warning('LogoTrimBox: falha ao medir logo', ['url' => $url, 'message' => $e->getMessage()]);
                $box = null;
            }
            if ($box !== null) {
                $out[$url] = $box;
            }
        }

        return $out;
    }

    /** @return array{x:int,y:int,w:int,h:int,nw:int,nh:int}|null */
    public static function for(string $url): ?array
    {
        if ($url === '' || ! preg_match('/\.(png|webp|gif)(\?.*)?$/i', $url)) {
            return null; // formatos sem transparência não têm margem para recortar
        }

        $key = 'logo_trim_box:'.sha1($url);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached['box'] ?? null;
        }

        $box = self::compute($url);
        // Falha de leitura expira em 1 h (storage pode estar sendo migrado); sucesso fica em cache.
        $box !== null
            ? Cache::forever($key, ['box' => $box])
            : Cache::put($key, ['box' => null], now()->addHour());

        return $box;
    }

    /** @return array{x:int,y:int,w:int,h:int,nw:int,nh:int}|null */
    private static function compute(string $url): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $bytes = self::read($url);
        if ($bytes === null || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $size = @getimagesizefromstring($bytes);
        if (! is_array($size) || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }

        try {
            $w = imagesx($img);
            $h = imagesy($img);
            $minX = $w;
            $minY = $h;
            $maxX = -1;
            $maxY = -1;
            // GD: alfa 0 = opaco, 127 = transparente. Mesmo limiar do canvas (alfa > 12 de 255).
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $alpha = (imagecolorat($img, $x, $y) >> 24) & 0x7F;
                    if ($alpha < 121) {
                        if ($x < $minX) {
                            $minX = $x;
                        }
                        if ($x > $maxX) {
                            $maxX = $x;
                        }
                        if ($y < $minY) {
                            $minY = $y;
                        }
                        if ($y > $maxY) {
                            $maxY = $y;
                        }
                    }
                }
            }
        } finally {
            imagedestroy($img);
        }

        if ($maxX < 0) {
            return ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h, 'nw' => $w, 'nh' => $h];
        }

        return ['x' => $minX, 'y' => $minY, 'w' => $maxX - $minX + 1, 'h' => $maxY - $minY + 1, 'nw' => $w, 'nh' => $h];
    }

    private static function read(string $url): ?string
    {
        if (str_starts_with($url, '/images/') || str_starts_with($url, 'images/')) {
            $path = public_path(ltrim($url, '/'));

            return is_file($path) ? (string) file_get_contents($path) : null;
        }

        $objectKey = RemoteStorage::extractObjectKeyFromUrl(strtok($url, '?') ?: $url);
        if ($objectKey === null || str_contains($objectKey, '..')) {
            return null;
        }

        try {
            $disk = (new StorageService(null))->disk();
            if ($disk->exists($objectKey)) {
                return $disk->get($objectKey);
            }
        } catch (\Throwable $e) {
            Log::debug('LogoTrimBox: storage indisponível', ['message' => $e->getMessage()]);
        }

        // Arquivo ainda no disco local (antes/durante a migração para o storage remoto).
        try {
            return Storage::disk('public')->exists($objectKey) ? Storage::disk('public')->get($objectKey) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
