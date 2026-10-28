<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Custo de anúncio informado pelo seller (Brasa Intelligence). Valor em centavos. */
class AdSpend extends Model
{
    protected $fillable = ['tenant_id', 'spent_on', 'source', 'campaign', 'amount_cents', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount_cents' => 'integer',
        ];
    }
}
