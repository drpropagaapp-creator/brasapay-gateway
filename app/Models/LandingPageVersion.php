<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class LandingPageVersion extends Model
{
    public const UPDATED_AT = null;

    public const KEEP = 30;

    protected $fillable = ['product_id', 'tenant_id', 'scope', 'scope_id', 'landing', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['landing' => 'array'];
    }

    /**
     * Registra a landing publicada quando ela mudou e mantém só as últimas versões.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function recordIfChanged(Product $product, string $scope, ?int $scopeId, ?array $before, ?array $after, ?int $userId): void
    {
        if ($after === null || ! Schema::hasTable('landing_page_versions')) {
            return;
        }
        if ($before !== null && json_encode($before) === json_encode($after)) {
            return;
        }

        static::query()->create([
            'product_id' => (string) $product->id,
            'tenant_id' => (int) $product->tenant_id,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'landing' => $after,
            'created_by_user_id' => $userId,
        ]);

        $keepIds = static::query()
            ->where('product_id', (string) $product->id)
            ->where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->orderByDesc('id')
            ->limit(self::KEEP)
            ->pluck('id');

        static::query()
            ->where('product_id', (string) $product->id)
            ->where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
