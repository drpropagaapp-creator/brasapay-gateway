<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const TYPE_CREDIT_SALE = 'credit_sale';

    /** Crédito ainda em liquidação (D+N / reserva); saldo em pending_* até clears_at. */
    public const TYPE_CREDIT_SALE_PENDING = 'credit_sale_pending';

    /** Estorno do crédito de venda (ação manual ou gateway). */
    public const TYPE_DEBIT_REFUND = 'debit_refund';

    /** Valor da venda bloqueado em saldo pendente (MED / contestação). */
    public const TYPE_MED_HOLD = 'med_hold';

    public const TYPE_WITHDRAWAL_REQUEST = 'withdrawal_request';

    public const TYPE_WITHDRAWAL_COMPLETE = 'withdrawal_complete';

    public const TYPE_WITHDRAWAL_REFUND = 'withdrawal_refund';

    /** Ajuste manual pela plataforma (admin). */
    public const TYPE_ADMIN_ADJUSTMENT = 'admin_adjustment';

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_CREDIT_SALE => 'Venda creditada',
            self::TYPE_CREDIT_SALE_PENDING => 'Venda em liquidação',
            self::TYPE_DEBIT_REFUND => 'Estorno',
            self::TYPE_MED_HOLD => 'MED / contestação',
            self::TYPE_WITHDRAWAL_REQUEST => 'Saque solicitado',
            self::TYPE_WITHDRAWAL_COMPLETE => 'Saque concluído',
            self::TYPE_WITHDRAWAL_REFUND => 'Saque estornado',
            self::TYPE_ADMIN_ADJUSTMENT => 'Ajuste admin',
        ];
    }

    protected $fillable = [
        'tenant_id',
        'order_id',
        'withdrawal_id',
        'bucket',
        'type',
        'credit_reference',
        'amount_gross',
        'amount_fee',
        'amount_net',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount_gross' => 'decimal:2',
            'amount_fee' => 'decimal:2',
            'amount_net' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Comissões de co-produção gravadas em meta da carteira.
     * No PostgreSQL o operador JSON do Eloquent (meta->chave) falha com boolean/json;
     * o backfill de afiliados já usa ->> por isso.
     */
    public function scopeCoproductionCommission(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $driver = $q->getConnection()->getDriverName();
            if ($driver === 'pgsql') {
                $q->whereRaw("(meta->>'coproduction_role') = ?", ['coproducer'])
                    ->orWhereRaw("(meta->>'coproduction') IN ('true', '1')")
                    ->orWhereRaw(
                        "NULLIF(meta->>'product_coproducer_id', '') IS NOT NULL"
                        ." AND (meta->>'product_coproducer_id') <> 'null'"
                    );

                return;
            }

            $q->where('meta->coproduction_role', 'coproducer')
                ->orWhere('meta->coproduction', true)
                ->orWhere('meta->coproduction', 1)
                ->orWhere('meta->coproduction', 'true')
                ->orWhereNotNull('meta->product_coproducer_id');
        });
    }
}
