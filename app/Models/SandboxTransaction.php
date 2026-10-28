<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pagamento ou saque simulado do sandbox da API. Não toca pedidos, carteiras, adquirentes nem saques reais.
 */
class SandboxTransaction extends Model
{
    use HasUuids;

    public const KIND_PAYMENT = 'payment';

    public const KIND_WITHDRAWAL = 'withdrawal';

    protected $fillable = [
        'tenant_id', 'api_application_id', 'api_key_id', 'kind', 'method', 'status',
        'amount_cents', 'currency', 'customer_email', 'metadata', 'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function amountDecimal(): float
    {
        return (float) bcdiv((string) $this->amount_cents, '100', 2);
    }
}
