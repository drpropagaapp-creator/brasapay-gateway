<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Divisão do bruto registrada uma única vez por pedido. Nunca é atualizada:
 * reprocessamentos (webhook repetido, job reexecutado) leem daqui.
 */
class OrderSplitSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'seller_tenant_id', 'rule_version', 'total_cents', 'slices', 'issues'];

    protected function casts(): array
    {
        return [
            'slices' => 'array',
            'issues' => 'array',
            'total_cents' => 'integer',
            'rule_version' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function available(): bool
    {
        return Schema::hasTable('order_split_snapshots');
    }

    /**
     * Retorna o snapshot do pedido; se não existir, grava o calculado agora.
     *
     * @param  callable(): array{version: int, total_cents: int, slices: list<array<string, mixed>>, issues: list<array<string, mixed>>}  $compute
     */
    public static function remember(Order $order, callable $compute): self
    {
        $existing = static::query()->where('order_id', $order->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $split = $compute();

        try {
            return static::query()->create([
                'order_id' => $order->id,
                'seller_tenant_id' => (int) $order->tenant_id,
                'rule_version' => (int) $split['version'],
                'total_cents' => (int) $split['total_cents'],
                'slices' => $split['slices'],
                'issues' => $split['issues'] ?: null,
            ]);
        } catch (QueryException $e) {
            // Corrida com outro processo: vale o que foi gravado primeiro.
            $winner = static::query()->where('order_id', $order->id)->first();
            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Snapshot de split é imutável.'));
    }
}
