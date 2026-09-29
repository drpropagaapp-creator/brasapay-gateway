<?php

namespace App\Services\Uazapi;

use App\Models\Product;
use App\Services\StorageService;

class UazapiProductMedia
{
    public static function publicUrl(?Product $product): ?string
    {
        if (! $product) {
            return null;
        }

        $path = $product->image;
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $tenantId = $product->tenant_id !== null ? (int) $product->tenant_id : null;
        $url = trim((new StorageService($tenantId))->url($path));
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return null;
        }

        return $url;
    }
}
