<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecoveryMessage extends Model
{
    protected $fillable = [
        'tenant_id', 'channel', 'event', 'step', 'subject_key', 'checkout_session_id', 'order_id', 'product_id',
        'recipient', 'status', 'skip_reason', 'error', 'sent_at', 'recovered_order_id', 'recovered_amount_cents', 'recovered_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'recovered_at' => 'datetime',
            'recovered_amount_cents' => 'integer',
        ];
    }
}
